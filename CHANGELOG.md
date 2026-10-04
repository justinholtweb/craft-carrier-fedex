# Release Notes for Carrier for FedEx

## 5.0.0 - 2026-10-03

- Initial release.
- FedEx through the REST APIs (Rate, Ship, Track, Pickup, Locations and Address Validation), with sandbox and production environments.
- OAuth client-credentials authentication, with the keys sent in the form body as FedEx requires. Tokens are cached for the hour FedEx grants and refreshed a minute early.
- Live rates for one service or every service at once, with account rates (falling back to list), transit days and estimated delivery dates.
- FedEx Ground and FedEx Home Delivery treated as one product: the right one is chosen for each address.
- Labels as PDF 4×6, PDF on a US Letter sheet, ZPL or PNG.
- Multi-package shipments of up to 40 packages, with one tracking number and label per package.
- Print return labels, with the parties swapped.
- International shipments, with customs commodities built from Carrier's customs lines and a choice of DDU or DDP.
- Cancellation by master tracking number.
- Tracking of up to 30 numbers per request, with full scan history mapped onto Carrier's statuses, delivery dates and who signed.
- FedEx location search for the checkout picker, and Hold at Location delivery to the chosen one.
- On-call pickups for FedEx Ground or FedEx Express.
- Address validation with the corrected address and residential/business classification.
- Signature, adult signature, Saturday delivery and residential delivery accessorials.
