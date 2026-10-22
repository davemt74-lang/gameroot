from pathlib import Path
root=Path(__file__).resolve().parents[1]
admin=(root/'public/admin.php').read_text()
core=(root/'app/core.php').read_text()
for provider in ('hostgator','openai','anthropic'):
    assert provider in (root/'app/portal.php').read_text()
assert 'portal_encrypt($key)' in (root/'app/portal.php').read_text()
assert 'portal_decrypt' in core
assert 'csrf_token' in core and 'hash_equals' in core
assert 'password_hash' in (root/'app/portal.php').read_text()
assert 'password_verify' in (root/'app/portal.php').read_text()
assert 'pending' in (root/'app/schema.sql').read_text()
assert 'portal_require_https' in (root/'public/admin.php').read_text()
assert 'portal_require_https' in (root/'public/register.php').read_text()
assert 'portal_require_https' in (root/'public/login.php').read_text()
assert 'type="password"' in admin
assert 'Saved' not in admin or 'Credential values are never returned' in admin
assert not (root/'var/config.php').exists()
assert '/var/*' in (root/'.gitignore').read_text()
assert not any((root/'public'/name).exists() for name in ('config.php','schema.sql','.env'))
print('PASS Portal static security contracts')

cpanel=(root/'app/cpanel.php').read_text()
assert 'CURLOPT_SSL_VERIFYPEER => true' in cpanel
assert 'CURLOPT_SSL_VERIFYHOST => 2' in cpanel
assert 'CURLOPT_RESOLVE => $resolved' in cpanel
assert 'CURLOPT_FOLLOWLOCATION => false' in cpanel
assert 'FILTER_FLAG_NO_PRIV_RANGE' in cpanel and 'FILTER_FLAG_NO_RES_RANGE' in cpanel
assert '/execute/Variables/get_user_information' in cpanel
assert "require_admin()" in admin
assert "test_cpanel" in admin

packages=(root/'app/packages.php').read_text()
package_ui=(root/'public/packages.php').read_text()
assert 'require_admin()' in package_ui and 'verify_csrf()' in package_ui
assert 'portal_require_https()' in package_ui
assert "billing_type" in packages and "one_time" in packages and "subscription" in packages
assert "HomeServer ownership packages must be a one-time purchase" in packages
assert "Cloud and subdomain hosting must use subscriptions" in packages
assert "beginTransaction()" in packages and "rollBack()" in packages
assert "ON DELETE CASCADE" in (root/'app/schema.sql').read_text()
assert "checkout" not in package_ui.lower() or "disabled" in package_ui.lower()

plans=(root/'public/plans.php').read_text()
account=(root/'public/account.php').read_text()
assert "require_user()" in plans and "verify_csrf()" in plans and "portal_require_https()" in plans
assert "select_customer_package" in plans and "customer_package_selections" in account
assert "No payment" in plans and "No charge" not in plans or "No payment" in plans
assert "customer_package_selections" in (root/'app/schema.sql').read_text()
assert "customer_package_selections" in packages
