Certloom normalized-domain release authority
============================================

resources/domain/accepted.json is intentionally absent in ordinary Certloom
releases until a genuine WordPress/MySQL/MariaDB normalized-domain acceptance
bundle has passed the reviewed DBAUTH finalization and authorized packaging
ceremony for the exact production payload.

The file is immutable release metadata. It is not a WordPress option, feature
flag, administrator setting, or request-controlled toggle. The per-site cutover
coordinator remains a second independent safety boundary after release authority
is present.
