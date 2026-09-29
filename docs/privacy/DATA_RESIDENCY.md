# Data residency evidence

Production Core readiness requires evidence for primary database, backup, object storage, log storage and cache countries, with verification time and reference. The check only verifies that the required configuration fields are present. Infrastructure owners must substantiate physical locations, replication, disaster recovery copies and vendor routing with deployment evidence. Missing evidence makes `privacy/compliance/check` fail.

The Control Plane has a separate database and payment integrations; its primary/backup/log residency and processor route must be reviewed independently. No application flag certifies physical placement.
