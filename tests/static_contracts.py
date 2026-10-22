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
