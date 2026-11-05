-- Dev/test databases. The runtime role is the owner in dev; production uses a least-privilege role
-- (see docs/DEPLOYMENT.md, Prompt 6) — but DB triggers, not GRANTs, are what enforce immutability.
CREATE DATABASE infernix_test;
