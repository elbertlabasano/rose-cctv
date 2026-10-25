# Rose account sign-in

RoseWatch uses Keycloak's authorization code flow with session-bound state and
PKCE S256. The existing email/password form remains available. Sign out ends
only the RoseWatch session; it does not sign employees out of other Rose apps.

## Server configuration

Install the locked PHP dependencies with `composer install`. Configure the
following values in the deployment's private `.env`, never a `VITE_` variable:

```dotenv
SSO_ENABLED=true
KEYCLOAK_BASE_URL=https://sso.rosebakeshop.net
KEYCLOAK_REALM=jathnier
KEYCLOAK_CLIENT_ID=watch
KEYCLOAK_CLIENT_SECRET=<the watch client's secret>
KEYCLOAK_REDIRECT_URI=https://watch.rosebakeshop.net/auth/keycloak/callback
SSO_IDCODE_CLAIM=idcode
```

The local callback registered for this client is
`http://127.0.0.1:8002/auth/keycloak/callback`. Run the local app on port 8002;
callbacks must exactly match Keycloak's allowed redirect URIs. Local HTTP needs
non-secure cookies. Production must use HTTPS, `APP_DEBUG=false`,
`SESSION_SECURE_COOKIE=true`, and `SESSION_SAME_SITE=lax`. Avoid sharing the
RoseWatch session cookie with other apps. Clear cached configuration after
changing private settings; rebuild the frontend when deploying the login UI.

The client must enable client authentication and standard flow, with S256 PKCE.
Implicit flow, direct access grants, and service accounts are unnecessary. No
browser CORS origin is needed for these server-side exchanges. The server must
reach Keycloak over verified TLS; TLS verification stays enabled and HTTP
redirects are disabled for token/UserInfo requests.

## Account linking

Run the additive migration
`2026_10_26_120000_add_rose_sso_identity_to_users.php` through the deployment's
normal migration process. It adds nullable `idcode`, `keycloak_issuer`, and
`keycloak_subject` columns with uniqueness constraints; it does not reseed users.
Never use `migrate:fresh`, `migrate:refresh`, or a test database reset on app data.

In **User management**, edit an existing account's **Employee ID code**. Set the
same exact value in Keycloak's managed `idcode` attribute. Codes are unique
strings, including leading zeros. Elbert's code is `20260211`. Rynhart's code
must be supplied by an administrator before he can use Rose sign-in.

Configure the client's `idcode` User Attribute mapper as a single string included
in **UserInfo**. Realm administrators should edit the managed attribute; employees
must not be able to assign themselves another employee's code.

On first sign-in, exactly one active local account must match the code. RoseWatch
records the trusted issuer and Keycloak subject. Later sign-ins must match both
the stored identity and code. Blank, malformed, multivalued, unknown, inactive,
or conflicting identities are rejected. Email and Keycloak roles are never used
to create accounts, assign permissions, or bypass branch restrictions. Existing
passwords, roles, teams, and branch access are preserved.

Changing a code does not clear an established identity link. If an identity was
deleted/recreated or linked incorrectly, an administrator must investigate and
explicitly repair the stored binding through a controlled server operation;
there is no automatic reassignment or browser-editable binding field.

## Verification and troubleshooting

- Select **Sign In With Rose Account**, authenticate in Keycloak, and confirm
  arrival at `/dashboard` with the existing RoseWatch permissions.
- Verify a second sign-in uses the established identity. Sign out locally and
  confirm password sign-in still works and other Rose sessions stay signed in.
- For `invalid_redirect_uri`, check port, scheme, host, and callback path exactly.
- A Keycloak `watch` session confirms authentication at Keycloak, but RoseWatch
  must still exchange the authorization code, fetch UserInfo, and match the local
  account before its dashboard can open.
- If RoseWatch reports that it cannot connect, check the **running PHP server's**
  outbound HTTPS access. Testing PHP in a different terminal or opening Keycloak
  in the browser does not prove the web server process has network access. Start
  the local server from an ordinary terminal outside a restricted agent session:
  `php -d upload_max_filesize=8M -d post_max_size=64M -S 127.0.0.1:8002 -t public dev-server.php`.
  Stop the old listener first. Keep TLS verification enabled.
- If account matching fails, check the active flag, exact local code, managed
  Keycloak attribute, single-string UserInfo mapper, and existing identity link.
- An expired, cancelled, or invalid-state attempt returns a safe login error;
  start again from the button. Handshakes expire after ten minutes and are consumed.
- Provider failures log only the exception class, numeric HTTP status, and numeric
  cURL error when available. Validation failures log a fixed failure code without
  employee details. cURL error 7 means the server could not establish a connection;
  HTTP 400/401 means it reached the provider and the request was rejected. Never
  log exception messages, raw handler context, credentials, callback query strings,
  token responses, or raw UserInfo. Do not store access or refresh tokens for this
  login flow. Keep callback query strings out of access logs.
- Disable `SSO_ENABLED` to hide the button and reject SSO attempts while retaining
  password sign-in.

Automated coverage is in `tests/Feature/KeycloakAuthenticationTest.php`. It uses
the real Socialite adapter with fake HTTP responses and the guarded SQLite
in-memory database, without `RefreshDatabase`. An actual employee sign-in is
still required to verify the live Keycloak user attribute and client settings.

Reference: the supplied `C:\apps\jathnier\rose-doc\sso.md` guide was adapted
for RoseWatch's `watch` client, callbacks, and `active` account flag.
[Keycloak adapter](https://socialiteproviders.com/Keycloak/) and
[Keycloak OIDC endpoints](https://www.keycloak.org/securing-apps/oidc-layers).
