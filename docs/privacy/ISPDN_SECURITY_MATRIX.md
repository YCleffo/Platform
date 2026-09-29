# ISPDn deployment security matrix

This is an evidence checklist for a deployment, not an assignment of a protection level. The actual level and controls require an approved threat model and responsible-person decision. Machine-readable fields are in [deployment-checklist.json](deployment-checklist.json).

| Area | Technical hook or evidence required | Deployment reference |
| --- | --- | --- |
| Threat model and harm assessment | Approved documents for the deployed system and subjects | Required, unset |
| Protection level | Determined from deployment threat model | Required, unset |
| Authentication and authorization | Core/Control Plane identity, scoped permissions, privileged CLI access | Role review required |
| Access logging and event registration | Allowlisted audit events and incident events | Retention and access review required |
| Backup and recovery | Restore drills, retention, reapplication of erasure after restore | Evidence required |
| Vulnerabilities and updates | Scan, patch and review schedule | Evidence required |
| Incident handling | 24/72 tracking, human submission evidence and escalation | Responsible person required |
| TLS and encryption | Transport, key management, storage and backup controls | Evidence required |
| Administrative access and secrets | Least privilege, separate lifecycle role, secret manager | Evidence required |
| Data residency | Primary, backup, object, log and cache evidence | Country and verification reference required |
| Processors and transfers | Contract/instruction and outbound decision registry | Evidence required |
