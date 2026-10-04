# Carrier for FedEx

FedEx for [Carrier](https://justinholt.com/plugins/craft-carrier), the shipping-carrier gateway for Craft Commerce 5, through the FedEx REST APIs at `apis.fedex.com`.

This package translates the FedEx APIs and nothing else. Packing, checkout, label storage, bulk printing, tracking schedules, the location picker and the log all live in Carrier.

## What it does

| | |
|---|---|
| Rates | Live rates for one service, or every service at once when a method shops. Your account rates (falling back to list rates), with transit days and an estimated delivery date |
| Labels | Domestic and international, up to 40 packages in one shipment (one tracking number and label per package) |
| Label formats | PDF 4×6, PDF on a US Letter sheet (label on the top half), ZPL (4×6 thermal) and PNG |
| Returns | Print return labels |
| International | Customs commodities and the commercial-invoice terms are built from Carrier's customs lines. Choose who pays duties: the recipient (DDU) or your account (DDP) |
| Void | The whole shipment, by its master tracking number |
| Tracking | Up to 30 numbers per request, with full scan history mapped onto Carrier's statuses, delivery dates and who signed |
| Locations | Search for FedEx Office stores and retail counters (Walgreens, Dollar General, authorised ship centres) for the checkout picker, and ship to the chosen one with Hold at Location |
| Collections | On-call pickups for FedEx Ground or FedEx Express (a setting chooses which) |
| Address validation | Street-level validation with FedEx's corrected address and residential/business classification |
| Accessorials | Signature, adult signature, Saturday delivery, residential delivery |

Ships from the United States and Puerto Rico, in pounds and inches.

## FedEx Ground and Home Delivery

FedEx treats Ground to a business and Ground to a home as two services, `FEDEX_GROUND` and `GROUND_HOME_DELIVERY`, and refuses the wrong one for the address. To a merchant they are one product. So:

- When a method shops every service, only the Ground that fits the customer's address is offered.
- When a method is set to either Ground, the right one is sent to FedEx for each address, and the rate and label are reported under the service the method asked for. The shipment notes when it was swapped.

FedEx Ground Economy (`SMART_POST`) is not offered. It needs a hub id and indicia that FedEx assigns per account.

## Setting up

1. Install Carrier, then this package: `composer require justinholtweb/craft-carrier-fedex` and `php craft plugin/install carrier-fedex`.
2. At [developer.fedex.com](https://developer.fedex.com/api/en-us/home.html), create a project with the **Ship**, **Rate**, **Track**, **Address Validation**, **Locations** and **Pickup** APIs, and link your FedEx shipping account to it.
3. Copy the project's **API key** and **Secret key**. FedEx issues **separate keys for its sandbox and for production**. Store them in environment variables.
4. In **Carrier → Connections**, add a FedEx connection:
   - **Environment**: Sandbox (`apis-sandbox.fedex.com`) or Production. It must match the keys.
   - **API key** and **Secret key**: from step 3.
   - **FedEx account number**: the nine-digit account labels are billed to.
   - **How parcels reach FedEx**: drop-off, a regular scheduled pickup, or pickups you book. FedEx prices some services differently depending on this.
   - **Pickups are for**: FedEx books Ground and Express collections separately. Choose which one Carrier books, and book the other in FedEx Ship Manager.
   - **Declare each parcel's value**: off by default. Turning it on raises FedEx's liability above the included $100, which FedEx charges for.
   - **International duties and taxes**: who pays.
5. Press **Test connection**. It requests a fresh access token from FedEx, which proves the keys.

**Production labels need certification.** Before FedEx enables production shipping for a project, it asks you to submit sample labels for approval. Plan for that before you go live.

**The sandbox is not the real world.** Sandbox rates are not your real prices, and sandbox tracking only knows FedEx's published mock tracking numbers.

Make sure your checkout collects a **phone number**. FedEx requires one for every shipper and recipient. Commerce addresses have no phone field, so map one in Carrier's settings.

## Hold at Location

FedEx locations are searched live by ZIP code or city, so there is nothing to import. FedEx Office stores are listed as stores and other counters as shops. When a method requires a pickup point, the label is created with FedEx's Hold at Location service for the chosen location, and it is never treated as a residential delivery.

Not every FedEx location accepts Hold at Location parcels. If FedEx refuses one, the refusal is shown on the shipment.

## Returns

A return label swaps the parties: the customer becomes the shipper and your store the recipient. It is billed to your account.

## Unverified

This package was written against the FedEx OpenAPI files and API Reference Guide without a live account. These points are marked **UNVERIFIED** in the code and should be checked in the sandbox before you go live:

- Where the currency sits in a rate answer. Both documented places are read.
- Hold at Location: the `holdAtLocationDetail.locationId` field, and whether the location's address must be sent as well.
- Location search: the result count, location type and company name fields. Opening hours are passed to templates as FedEx sends them (`extra.storeHours`), not formatted.
- Pickups: which of the pickup type, total weight and package count are required (the pickup type is not sent), and that the ready time is read as local time.
- Address validation: the `DPV` and `Resolved` attributes as the strings `"true"`/`"false"`.
- Fields that are not in the research: signature options, Saturday delivery, declared value, the payor on shipping charges, and `deliveryDetails.receivedByName` for who signed.
- Street address limits (three lines of 35 characters).
- The current label-certification process.

## Requirements

Craft CMS 5.3+, Craft Commerce 5, Carrier 5, PHP 8.2+.

## Licence

The Craft License. See [LICENSE.md](LICENSE.md). Carrier for FedEx is free: no editions, no licence key, and no licensing code in the plugin. It needs [Carrier](https://justinholt.com/plugins/craft-carrier), which is commercial.
