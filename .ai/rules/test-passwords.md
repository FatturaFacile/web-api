---
paths:
  - "tests/**"
  - "modules/*/tests/**"
  - "packages/*/tests/**"
---

# Passwords in tests

- Never write a password in a test as a fixed string, not even in part: a literal value and a fixed prefix joined to a random suffix are both reported by GitGuardian on the pull request.
- Generate every password at runtime. For one that satisfies the password policy (length, both cases, digits, symbols): `Str::password(16).chr(random_int(65, 90)).chr(random_int(97, 122)).random_int(0, 9)`.
- For a password the policy rejects, generate it as well: `Str::lower(Str::random(5))`.
- For a wrong password, change the right one: `$password.'x'`.
- Apply the same rule to secrets, tokens and API keys used as test data.
