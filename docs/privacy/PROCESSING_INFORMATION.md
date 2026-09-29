# Processing information response

The Core `processing_information` DSAR response is built by `ProcessingInformationService` from subject-scoped contributors and deployment definitions. It includes whether processing was found, basis and purpose codes, methods, categories and sources, periods, rights procedure, recipients, processors, transfer decisions and compliance measures. It does not enumerate another subject's records. The self and verified representative routes use the existing DSAR authorization boundary.

Issuance records a digest and execution metadata through `DataSubjectRequestExecutionService`; the response body is never persisted in evidence. Only successful issuance satisfies the request deadline. Deployment must populate complete purpose, role, recipient, processor and transfer definitions before using this as a production response.
