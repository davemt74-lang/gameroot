<?php
declare(strict_types=1);
require_once __DIR__ . '/portal.php';

function package_table_sql(): string {
    return "CREATE TABLE IF NOT EXISTS package_details (
      product_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
      delivery_type VARCHAR(16) NOT NULL,
      billing_type VARCHAR(16) NOT NULL,
      billing_interval VARCHAR(16) NULL,
      currency CHAR(3) NOT NULL DEFAULT 'USD',
      features_json TEXT NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}
function package_schema_ready(PDO $pdo): bool {
    $q = $pdo->query("SHOW TABLES LIKE 'package_details'");
    return $q->fetchColumn() !== false;
}
function upgrade_package_schema(PDO $pdo): void {
    // Fixed, reviewed DDL only. Re-running is safe; no product or account data is removed.
    $pdo->exec(package_table_sql());
}
function validate_package(array $input): array {
    $sku = strtolower(trim((string)($input['sku'] ?? '')));
    $name = trim((string)($input['name'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));
    $delivery = (string)($input['delivery_type'] ?? '');
    $billing = (string)($input['billing_type'] ?? '');
    $interval = trim((string)($input['billing_interval'] ?? ''));
    $price = trim((string)($input['price'] ?? ''));
    $features = trim((string)($input['features'] ?? ''));
    if (!preg_match('/^[a-z][a-z0-9_-]{2,79}$/D', $sku)) throw new DomainException('SKU must be 3–80 lowercase letters, numbers, underscores or dashes.');
    if (strlen($name)<3 || strlen($name)>150) throw new DomainException('Package name must be 3–150 characters.');
    if ($description==='' || strlen($description)>10000) throw new DomainException('Enter a package description (maximum 10,000 characters).');
    if (!in_array($delivery,['homeserver','cloud','hosting_addon'],true)) throw new DomainException('Invalid delivery type.');
    if (!in_array($billing,['one_time','subscription','free'],true)) throw new DomainException('Invalid billing type.');
    if ($delivery==='homeserver' && $billing==='subscription') throw new DomainException('HomeServer ownership packages must be a one-time purchase or free.');
    if (in_array($delivery,['cloud','hosting_addon'],true) && $billing==='one_time') throw new DomainException('Cloud and subdomain hosting must use subscriptions or a free plan.');
    if ($billing==='subscription' && !in_array($interval,['monthly','annual'],true)) throw new DomainException('Choose monthly or annual billing.');
    if ($billing!=='subscription' && $interval!=='') throw new DomainException('Only subscriptions have a billing interval.');
    if (!preg_match('/^(?:0|[1-9][0-9]{0,6})(?:\.[0-9]{1,2})?$/D',$price)) throw new DomainException('Enter a valid nonnegative price.');
    $parts=explode('.',$price,2);
    $cents=(int)$parts[0]*100+(int)str_pad($parts[1]??'',2,'0');
    if ($cents>100000000) throw new DomainException('Price exceeds the maximum allowed amount.');
    if ($billing==='free' && $cents!==0) throw new DomainException('Free packages must have a zero price.');
    if ($billing!=='free' && $cents===0) throw new DomainException('Paid packages must have a positive price.');
    $lines=array_values(array_filter(array_map('trim',preg_split('/\r\n|\r|\n/',$features)?:[]),fn($s)=>$s!==''));
    if (count($lines)>30) throw new DomainException('Limit packages to 30 feature lines.');
    foreach($lines as $line) if(strlen($line)>160) throw new DomainException('Each feature must fit within 160 characters.');
    return ['sku'=>$sku,'name'=>$name,'description'=>$description,'delivery_type'=>$delivery,
      'billing_type'=>$billing,'billing_interval'=>$billing==='subscription'?$interval:null,
      'price_cents'=>$cents,'features'=>$lines];
}
function create_package(PDO $pdo,array $input): int {
    if (!package_schema_ready($pdo)) throw new DomainException('Upgrade the package schema before creating packages.');
    $v=validate_package($input);
    $pdo->beginTransaction();
    try {
        $q=$pdo->prepare('INSERT INTO products(sku,name,description,price_cents,active) VALUES (?,?,?,?,0)');
        $q->execute([$v['sku'],$v['name'],$v['description'],$v['price_cents']]);
        $id=(int)$pdo->lastInsertId();
        $q=$pdo->prepare('INSERT INTO package_details(product_id,delivery_type,billing_type,billing_interval,currency,features_json) VALUES (?,?,?,?,?,?)');
        $q->execute([$id,$v['delivery_type'],$v['billing_type'],$v['billing_interval'],'USD',json_encode($v['features'],JSON_THROW_ON_ERROR)]);
        $pdo->commit();
        return $id;
    } catch(Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException && $e->getCode()==='23000') throw new DomainException('That package SKU is already used.');
        throw $e;
    }
}
function set_package_active(PDO $pdo,int $id,bool $active): void {
    if ($id<1) throw new DomainException('Invalid package.');
    $q=$pdo->prepare('UPDATE products p INNER JOIN package_details d ON d.product_id=p.id SET p.active=? WHERE p.id=?');
    $q->execute([$active?1:0,$id]);
    if($q->rowCount()===0) {
        $check=$pdo->prepare('SELECT p.id FROM products p INNER JOIN package_details d ON d.product_id=p.id WHERE p.id=?');
        $check->execute([$id]);
        if (!$check->fetchColumn()) throw new DomainException('Package not found.');
    }
}
function list_packages(PDO $pdo,bool $activeOnly=false): array {
    if (!package_schema_ready($pdo)) return [];
    $sql='SELECT p.id,p.sku,p.name,p.description,p.price_cents,p.active,d.delivery_type,d.billing_type,d.billing_interval,d.currency,d.features_json FROM products p INNER JOIN package_details d ON d.product_id=p.id';
    if($activeOnly) $sql.=' WHERE p.active=1';
    return $pdo->query($sql.' ORDER BY p.id DESC')->fetchAll();
}

function update_package(PDO $pdo,int $id,array $input): void {
    if ($id<1) throw new DomainException('Invalid package.');
    $v=validate_package($input);
    $pdo->beginTransaction();
    try {
        $q=$pdo->prepare('SELECT p.id,d.delivery_type,d.billing_type FROM products p INNER JOIN package_details d ON d.product_id=p.id WHERE p.id=? FOR UPDATE');
        $q->execute([$id]);$old=$q->fetch();
        if (!$old) throw new DomainException('Package not found.');
        $q=$pdo->prepare('SELECT (SELECT COUNT(*) FROM orders WHERE product_id=?) + (SELECT COUNT(*) FROM entitlements WHERE product_id=?)');
        $q->execute([$id,$id]);
        if ((int)$q->fetchColumn()>0 && ($old['delivery_type']!==$v['delivery_type'] || $old['billing_type']!==$v['billing_type'])) {
            throw new DomainException('Delivery and ownership model cannot change after purchase. Create a new package instead.');
        }
        $q=$pdo->prepare('UPDATE products SET sku=?,name=?,description=?,price_cents=? WHERE id=?');
        $q->execute([$v['sku'],$v['name'],$v['description'],$v['price_cents'],$id]);
        $q=$pdo->prepare('UPDATE package_details SET delivery_type=?,billing_type=?,billing_interval=?,features_json=? WHERE product_id=?');
        $q->execute([$v['delivery_type'],$v['billing_type'],$v['billing_interval'],json_encode($v['features'],JSON_THROW_ON_ERROR),$id]);
        $pdo->commit();
    } catch(Throwable $e) {
        if($pdo->inTransaction()) $pdo->rollBack();
        if($e instanceof PDOException && $e->getCode()==='23000') throw new DomainException('That package SKU is already used.');
        throw $e;
    }
}
