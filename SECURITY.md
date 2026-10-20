# Security policy

## Supported version

Security fixes are made on the newest revision of the `main` branch and published as a new
tagged release (`vX.Y.Z`); only the latest tag is supported. Older releases are not patched, so
upgrade using [docs/MIGRATION.md](docs/MIGRATION.md).

## Reporting a vulnerability

Do not open a public issue for a suspected vulnerability. Report it privately, either through
GitHub's **Security → Report a vulnerability** form for this repository or, when that form is
not available, by e-mail to **contact@tryhackx.org** with "Security" in the subject. Include:

- affected revision and deployment environment;
- a minimal reproduction;
- expected and observed impact;
- whether real user data or credentials were accessed.

Please do not test against installations you do not own or have explicit permission to assess.
Secrets, personal data and uploaded files must not be included in the report. Acknowledgement,
triage and disclosure timing will be coordinated in the private advisory or the e-mail thread.

## Operational scope

The application handles untrusted uploads and payment callbacks. Operators should keep
dependencies and PHP/Python runtimes supported, serve only `public/` as the web root, keep
`config/`, `data/` and `uploads/` outside direct HTTP access, configure a canonical HTTPS
origin, and run the documented cleanup and mail workers.
