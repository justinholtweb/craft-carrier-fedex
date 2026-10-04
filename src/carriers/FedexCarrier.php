<?php

namespace justinholtweb\carrierfedex\carriers;

use Craft;
use justinholtweb\carrier\auth\OAuth2ClientCredentials;
use justinholtweb\carrier\base\Accessorial;
use justinholtweb\carrier\base\ActionResult;
use justinholtweb\carrier\base\AddressResult;
use justinholtweb\carrier\base\AuthInterface;
use justinholtweb\carrier\base\Capabilities;
use justinholtweb\carrier\base\Carrier;
use justinholtweb\carrier\base\Feature;
use justinholtweb\carrier\base\Field;
use justinholtweb\carrier\base\HealthResult;
use justinholtweb\carrier\base\PointPage;
use justinholtweb\carrier\base\RateResult;
use justinholtweb\carrier\base\ShipmentResult;
use justinholtweb\carrier\base\TrackingStatus;
use justinholtweb\carrier\base\Transport;
use justinholtweb\carrier\helpers\LabelFormat;
use justinholtweb\carrier\helpers\Units;
use justinholtweb\carrier\models\canonical\Address;
use justinholtweb\carrier\models\canonical\Label;
use justinholtweb\carrier\models\canonical\Parcel;
use justinholtweb\carrier\models\canonical\PickupPoint;
use justinholtweb\carrier\models\canonical\PickupRequest;
use justinholtweb\carrier\models\canonical\PointQuery;
use justinholtweb\carrier\models\canonical\Rate;
use justinholtweb\carrier\models\canonical\ShipmentRef;
use justinholtweb\carrier\models\canonical\ShipmentRequest;
use justinholtweb\carrier\models\canonical\TrackingEvent;
use justinholtweb\carrier\models\canonical\TrackingInfo;
use justinholtweb\carrier\models\Connection;

/**
 * FedEx, through the REST APIs at apis.fedex.com (Rate, Ship, Track, Pickup, Locations and
 * Address Validation).
 *
 * Written against the OpenAPI JSON the developer.fedex.com reference pages load, and the API
 * Reference Guide, without a live account. The traps:
 *
 * - **Rate says `shipDateStamp`, Ship says `shipDatestamp`.** One capital letter. Get it wrong and
 *   FedEx quietly uses today.
 * - **Rate takes one `recipient` object; Ship takes a `recipients` array.**
 * - **Dimensions are integers** (no decimals, at most 999), so they are rounded up to the inch.
 *   Weights take decimals; `totalWeight` takes one.
 * - **Credentials go in the form body**, not in Basic auth — the opposite of UPS. Tokens last an
 *   hour and `expires_in` is a real integer.
 * - **Ground to a residence must be Home Delivery.** `FEDEX_GROUND` to a residential address is
 *   refused, and shopping returns both. Shopping keeps only the one that fits the address; asking
 *   for either Ground by code sends the right one on the wire and reports it under the code that
 *   was asked for, so a method set to "FedEx Ground" still prices and ships to a house.
 * - **Single-shot multi-piece is synchronous up to 40 packages.** Beyond that FedEx goes
 *   asynchronous with a job id to poll, which this carrier does not do: more than 40 parcels is
 *   refused before anything is sent.
 * - **Tracking can answer HTTP 200 with errors inside**, per number. `NOTFOUND` is reported as not
 *   found; any other per-number error is skipped rather than guessed at.
 * - **Returns swap the parties** (the customer becomes the shipper) and are a separate Ship call
 *   with the `RETURN_SHIPMENT` special service.
 * - **International needs commodities to be rated at all**, not only to ship. Both are built from
 *   `$request->customsItems`; a shipment without them is refused rather than invented.
 * - **Sandbox only knows FedEx's mock tracking numbers**, and sandbox rates are not real.
 * - **Production labels need label certification**: FedEx checks sample labels before enabling
 *   production Ship for a project (UNVERIFIED for the current process).
 *
 * Tracking: `CA` (cancelled by sender) maps to `cancelled` and `HP` (ready at a Hold at Location)
 * to `readyForPickup`, which the research table predates; delivery-change requests (`RR`, `RM`,
 * `RA`) and return-label link events (`RP`, `RG`, `RD`, `LC`) are informational, so the shipment
 * keeps the status of the last scan that was not one of them.
 *
 * UNVERIFIED — confirm in the sandbox before relying on these:
 * - `ratedShipmentDetails[].currency` versus `shipmentRateDetail.currency`; both are read.
 * - Hold at Location: `shipmentSpecialServices.holdAtLocationDetail.locationId`, and whether the
 *   location's address must also be sent.
 * - Location search: `resultsRequested`, `locationType` and `contactAndAddress.contact.companyName`
 *   on results; `storeHours` is passed through raw in the point's `extra`.
 * - Pickup: which of `pickupType`, `totalWeight` and `packageCount` are required (`pickupType` is
 *   not sent), and that `readyDateTimestamp` is read as the pickup location's local time despite
 *   the `Z` in FedEx's own example.
 * - Address validation `attributes` (`DPV`, `Resolved`) as the strings `"true"`/`"false"`.
 * - Special services not in the research: `SIGNATURE_OPTION` with `signatureOptionType`
 *   `DIRECT`/`ADULT`, `SATURDAY_DELIVERY`, package `declaredValue`, and `payor.responsibleParty`
 *   on `shippingChargesPayment`.
 * - `deliveryDetails.receivedByName` for who signed.
 * - `streetLines` limits (three lines of 35 characters).
 */
class FedexCarrier extends Carrier
{
    private const SANDBOX = 'https://apis-sandbox.fedex.com';

    private const PRODUCTION = 'https://apis.fedex.com';

    /** Single-shot multi-piece shipments are synchronous up to this many packages. */
    private const MAX_PIECES = 40;

    private const STATUS_MAP = [
        'OC' => TrackingStatus::PRE_TRANSIT,
        'MD' => TrackingStatus::PRE_TRANSIT,
        'PD' => TrackingStatus::PRE_TRANSIT,
        'DS' => TrackingStatus::PRE_TRANSIT,
        'PU' => TrackingStatus::IN_TRANSIT,
        'DO' => TrackingStatus::IN_TRANSIT,
        'IP' => TrackingStatus::IN_TRANSIT,
        'IT' => TrackingStatus::IN_TRANSIT,
        'AR' => TrackingStatus::IN_TRANSIT,
        'AF' => TrackingStatus::IN_TRANSIT,
        'DP' => TrackingStatus::IN_TRANSIT,
        'TR' => TrackingStatus::IN_TRANSIT,
        'CC' => TrackingStatus::IN_TRANSIT,
        'CP' => TrackingStatus::IN_TRANSIT,
        'EA' => TrackingStatus::IN_TRANSIT,
        'AC' => TrackingStatus::IN_TRANSIT,
        'OX' => TrackingStatus::IN_TRANSIT,
        'PM' => TrackingStatus::IN_TRANSIT,
        'CH' => TrackingStatus::IN_TRANSIT,
        'US' => TrackingStatus::IN_TRANSIT,
        'AE' => TrackingStatus::IN_TRANSIT,
        'AO' => TrackingStatus::IN_TRANSIT,
        'DY' => TrackingStatus::IN_TRANSIT,
        'IN' => TrackingStatus::IN_TRANSIT,
        'OD' => TrackingStatus::OUT_FOR_DELIVERY,
        'HP' => TrackingStatus::READY_FOR_PICKUP,
        'DL' => TrackingStatus::DELIVERED,
        'DE' => TrackingStatus::EXCEPTION,
        'DD' => TrackingStatus::EXCEPTION,
        'SE' => TrackingStatus::EXCEPTION,
        'CD' => TrackingStatus::EXCEPTION,
        'CA' => TrackingStatus::CANCELLED,
        'RS' => TrackingStatus::RETURNED,
        'RT' => TrackingStatus::RETURNED,
    ];

    /** Codes that say something happened *to* the shipment without moving it. */
    private const INFORMATIONAL = ['RR', 'RM', 'RA', 'RP', 'RG', 'RD', 'LC'];

    /** `commit.daysInTransit` and `operationalDetail.transitTime` are words, not numbers. */
    private const DAY_WORDS = [
        'ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5, 'SIX' => 6, 'SEVEN' => 7,
        'EIGHT' => 8, 'NINE' => 9, 'TEN' => 10, 'ELEVEN' => 11, 'TWELVE' => 12, 'THIRTEEN' => 13,
        'FOURTEEN' => 14, 'FIFTEEN' => 15, 'SIXTEEN' => 16, 'SEVENTEEN' => 17, 'EIGHTEEN' => 18,
        'NINETEEN' => 19, 'TWENTY' => 20,
    ];

    /** Rate may still echo retired names. */
    private const LEGACY_SERVICES = [
        'INTERNATIONAL_PRIORITY' => 'FEDEX_INTERNATIONAL_PRIORITY',
    ];

    private const GROUND_PAIR = ['FEDEX_GROUND', 'GROUND_HOME_DELIVERY'];

    public static function handle(): string
    {
        return 'fedex';
    }

    public static function displayName(): string
    {
        return 'FedEx';
    }

    public static function region(): string
    {
        return Craft::t('carrier', 'United States');
    }

    public static function description(): string
    {
        return Craft::t('carrier', 'FedEx rates, PDF, PNG and ZPL labels, returns, tracking, Hold at Location, pickups and address validation through the FedEx REST APIs.');
    }

    public static function setupUrl(): ?string
    {
        return 'https://developer.fedex.com/api/en-us/home.html';
    }

    public static function capabilities(): Capabilities
    {
        return Capabilities::make()
            ->supports(
                Feature::RATES, Feature::LABELS, Feature::CANCEL, Feature::TRACKING, Feature::PICKUPS,
                Feature::RETURNS, Feature::ADDRESS_VALIDATION, Feature::INTERNATIONAL, Feature::MULTI_PIECE,
            )
            ->points(Capabilities::POINTS_SEARCH, PickupPoint::TYPE_STORE, PickupPoint::TYPE_SHOP)
            ->labelFormats(LabelFormat::PDF_4X6, LabelFormat::PDF_LETTER, LabelFormat::ZPL, LabelFormat::PNG)
            ->accessorials(Accessorial::SIGNATURE, Accessorial::ADULT_SIGNATURE, Accessorial::SATURDAY_DELIVERY, Accessorial::RESIDENTIAL_DELIVERY)
            ->originCountries('US', 'PR')
            // 150 lb in your own packaging, for Express and Ground alike.
            ->maxParcelWeight(68038)
            ->trackingBatch(30)
            ->withSandbox();
    }

    public static function settingsFields(): array
    {
        return [
            Field::environment([
                'instructions' => 'FedEx issues separate keys for its sandbox (apis-sandbox.fedex.com). Sandbox rates are not real and its labels are not valid.',
            ]),
            Field::heading('FedEx project', 'Create a project at developer.fedex.com with the Ship, Rate, Track, Address Validation, Locations and Pickup APIs, and link your FedEx account to it.'),
            Field::secret('clientId', 'API key', ['required' => true]),
            Field::secret('clientSecret', 'Secret key', ['required' => true]),
            Field::text('accountNumber', 'FedEx account number', [
                'required' => true,
                'instructions' => 'The nine-digit account labels are billed to.',
                'placeholder' => '123456789',
            ]),
            Field::heading('Options'),
            Field::select('pickupType', 'How parcels reach FedEx', [
                'DROPOFF_AT_FEDEX_LOCATION' => 'I drop them off',
                'USE_SCHEDULED_PICKUP' => 'A regular scheduled pickup',
                'CONTACT_FEDEX_TO_SCHEDULE' => 'I book a pickup when I need one',
            ], ['instructions' => 'FedEx prices some services differently depending on this.']),
            Field::select('pickupCarrier', 'Pickups are for', [
                'FDXG' => 'FedEx Ground',
                'FDXE' => 'FedEx Express',
            ], ['instructions' => 'FedEx books Express and Ground collections separately. Book the other one in FedEx Ship Manager.']),
            Field::boolean('declareValue', 'Declare each parcel’s value', [
                'instructions' => 'Raises FedEx’s liability above the included $100. FedEx charges for it.',
            ]),
            Field::select('dutiesPayer', 'International duties and taxes', [
                'RECIPIENT' => 'Recipient pays (DDU)',
                'SENDER' => 'Bill my FedEx account (DDP)',
            ]),
        ];
    }

    /**
     * US service types from the API Reference Guide. Ground Economy (`SMART_POST`) is left out:
     * it needs a hub id and indicia from FedEx before it can ship anything.
     */
    public static function services(): array
    {
        return [
            'FEDEX_GROUND' => ['name' => 'FedEx Ground', 'international' => true, 'points' => false],
            'GROUND_HOME_DELIVERY' => ['name' => 'FedEx Home Delivery', 'international' => false, 'points' => false],
            'FEDEX_EXPRESS_SAVER' => ['name' => 'FedEx Express Saver', 'international' => false, 'points' => false],
            'FEDEX_2_DAY' => ['name' => 'FedEx 2Day', 'international' => false, 'points' => false],
            'FEDEX_2_DAY_AM' => ['name' => 'FedEx 2Day A.M.', 'international' => false, 'points' => false],
            'STANDARD_OVERNIGHT' => ['name' => 'FedEx Standard Overnight', 'international' => false, 'points' => false],
            'PRIORITY_OVERNIGHT' => ['name' => 'FedEx Priority Overnight', 'international' => false, 'points' => false],
            'FIRST_OVERNIGHT' => ['name' => 'FedEx First Overnight', 'international' => false, 'points' => false],
            'FEDEX_INTERNATIONAL_CONNECT_PLUS' => ['name' => 'FedEx International Connect Plus', 'international' => true, 'points' => false],
            'INTERNATIONAL_ECONOMY' => ['name' => 'FedEx International Economy', 'international' => true, 'points' => false],
            'FEDEX_INTERNATIONAL_PRIORITY' => ['name' => 'FedEx International Priority', 'international' => true, 'points' => false],
            'FEDEX_INTERNATIONAL_PRIORITY_EXPRESS' => ['name' => 'FedEx International Priority Express', 'international' => true, 'points' => false],
            'INTERNATIONAL_FIRST' => ['name' => 'FedEx International First', 'international' => true, 'points' => false],
        ];
    }

    public static function trackingUrl(string $trackingNumber): ?string
    {
        return 'https://www.fedex.com/fedextrack/?trknbr=' . rawurlencode($trackingNumber);
    }

    // =========================================================================
    // Plumbing
    // =========================================================================

    protected function buildAuth(): ?AuthInterface
    {
        return new OAuth2ClientCredentials(
            tokenUrl: static fn(Connection $connection) => ($connection->getSetting('environment', 'production') === 'sandbox' ? self::SANDBOX : self::PRODUCTION)
                . '/oauth/token',
        );
    }

    protected function buildTransport(): Transport
    {
        return (new Transport())
            ->setBaseUri($this->isSandbox() ? self::SANDBOX : self::PRODUCTION)
            ->setDefaultHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-locale' => 'en_US',
            ]);
    }

    /**
     * Minting a token proves the API key and secret; it is the one FedEx call that needs no
     * address, parcel or tracking number.
     */
    protected function probe(): HealthResult
    {
        $auth = $this->auth();

        if ($auth === null || !$auth->reauthenticate()) {
            return HealthResult::fail(
                Craft::t('carrier', 'FedEx did not issue an access token.'),
                [
                    Craft::t('carrier', 'Check the API key and secret key against your project at developer.fedex.com.'),
                    Craft::t('carrier', 'Sandbox and production keys are different. Make sure the environment above matches the keys.'),
                ],
            );
        }

        return HealthResult::pass(Craft::t('carrier', 'Connected to FedEx.'), [
            Craft::t('carrier', 'Account') => (string)$this->setting('accountNumber', ''),
            Craft::t('carrier', 'Environment') => $this->isSandbox() ? Craft::t('carrier', 'Sandbox') : Craft::t('carrier', 'Production'),
        ]);
    }

    private function account(): array
    {
        return ['value' => (string)$this->setting('accountNumber', '')];
    }

    // =========================================================================
    // Rates
    // =========================================================================

    /**
     * Rates one service, or shops them all by leaving `serviceType` out.
     */
    public function rate(ShipmentRequest $request): RateResult
    {
        $shopping = $request->serviceCode === '';

        if ($request->isInternational() && $request->customsItems === []) {
            return RateResult::failed(Craft::t('carrier', 'FedEx needs customs lines to price an international shipment.'));
        }

        [$from, $to] = $this->parties($request);
        $residential = $this->isResidential($request, $to);
        $recipient = ['address' => $this->address($to)];
        $recipient['address']['residential'] = $residential;

        $shipment = [
            'shipper' => ['address' => $this->address($from)],
            'recipient' => $recipient,
            'shipDateStamp' => $this->shipDate($request),
            'pickupType' => (string)$this->setting('pickupType', 'DROPOFF_AT_FEDEX_LOCATION'),
            'packagingType' => 'YOUR_PACKAGING',
            'rateRequestType' => ['ACCOUNT', 'LIST'],
            'preferredCurrency' => $request->currency ?: 'USD',
            'totalPackageCount' => count($request->parcels),
            'requestedPackageLineItems' => array_map(fn(Parcel $p) => $this->lineItem($p, $request, false), $request->parcels),
        ];

        if (!$shopping) {
            $shipment['serviceType'] = $this->wireService($request->serviceCode, $request, $residential);
        }

        if (!$request->isReturn && in_array(Accessorial::SATURDAY_DELIVERY, $request->accessorials, true)) {
            $shipment['shipmentSpecialServices'] = ['specialServiceTypes' => ['SATURDAY_DELIVERY']];
        }

        if ($request->isInternational()) {
            $shipment['customsClearanceDetail'] = [
                'dutiesPayment' => ['paymentType' => $this->dutiesPayer()],
                'commodities' => $this->commodities($request),
            ];
        }

        $response = $this->transport()->post('/rate/v1/rates/quotes', [
            'accountNumber' => $this->account(),
            'rateRequestControlParameters' => [
                'returnTransitTimes' => true,
                'servicesNeededOnRateFailure' => true,
            ],
            'requestedShipment' => $shipment,
        ]);

        if (!$response->ok()) {
            return RateResult::failed($response->errorMessage());
        }

        $services = self::services();
        $domestic = !$request->isInternational();
        $rates = [];

        foreach ((array)$response->at('output.rateReplyDetails', []) as $reply) {
            if (!is_array($reply)) {
                continue;
            }

            $wire = (string)($reply['serviceType'] ?? '');
            $code = self::LEGACY_SERVICES[$wire] ?? $wire;

            if ($code === '') {
                continue;
            }

            if ($shopping) {
                if (!isset($services[$code])) {
                    continue;
                }

                // Both Grounds come back; only one of them will take this parcel.
                if ($domestic && in_array($code, self::GROUND_PAIR, true) && $code !== ($residential ? 'GROUND_HOME_DELIVERY' : 'FEDEX_GROUND')) {
                    continue;
                }
            } else {
                $code = $request->serviceCode;
            }

            $detail = self::pickRate((array)($reply['ratedShipmentDetails'] ?? []), ['ACCOUNT', 'LIST']);
            $amount = round((float)($detail['totalNetCharge'] ?? 0), 2);

            if ($amount <= 0) {
                continue;
            }

            $notes = [];

            if ($code !== $wire && !isset(self::LEGACY_SERVICES[$wire])) {
                $notes[] = Craft::t('carrier', 'Priced as {service} for this address.', ['service' => $services[$wire]['name'] ?? $wire]);
            }

            foreach ((array)($reply['customerMessages'] ?? []) as $message) {
                $text = is_array($message) ? (string)($message['message'] ?? '') : (string)$message;

                if ($text !== '') {
                    $notes[] = $text;
                }
            }

            $rates[] = new Rate([
                'serviceCode' => $code,
                'serviceName' => $services[$code]['name'] ?? (string)($reply['serviceName'] ?? $code),
                'amount' => $amount,
                'currency' => strtoupper((string)($detail['currency'] ?? $detail['shipmentRateDetail']['currency'] ?? ($request->currency ?: 'USD'))),
                'transitDays' => self::days($reply['commit']['daysInTransit'] ?? $reply['operationalDetail']['transitTime'] ?? null),
                'estimatedDelivery' => self::isoDate($reply['commit']['dateDetail']['dayFormat'] ?? $reply['operationalDetail']['deliveryDate'] ?? null),
                'notes' => $notes,
            ]);
        }

        return RateResult::ok($rates, self::alerts($response->at('output.alerts')));
    }

    // =========================================================================
    // Labels
    // =========================================================================

    public function createShipment(ShipmentRequest $request): ShipmentResult
    {
        if ($request->serviceCode === '') {
            return ShipmentResult::rejected(Craft::t('carrier', 'Choose a FedEx service before buying a label.'));
        }

        if ($request->parcels === []) {
            return ShipmentResult::rejected(Craft::t('carrier', 'There are no parcels to ship.'));
        }

        if (count($request->parcels) > self::MAX_PIECES) {
            return ShipmentResult::rejected(Craft::t('carrier', 'FedEx creates at most {max} packages in one shipment. Split this into smaller shipments.', ['max' => self::MAX_PIECES]));
        }

        if ($request->isInternational() && $request->customsItems === []) {
            return ShipmentResult::rejected(Craft::t('carrier', 'An international FedEx shipment needs customs lines, and this one has none.'));
        }

        [$from, $to] = $this->parties($request);
        $residential = $this->isResidential($request, $to);
        $service = $this->wireService($request->serviceCode, $request, $residential);
        $format = $this->labelFormatFor($request);
        [$imageType, $stock] = self::labelSpec($format);
        $shipDate = $this->shipDate($request);

        $recipient = $this->party($to);
        $recipient['address']['residential'] = $residential;

        $shipment = [
            'shipper' => $this->party($from),
            'recipients' => [$recipient],
            'shipDatestamp' => $shipDate,
            'serviceType' => $service,
            'packagingType' => 'YOUR_PACKAGING',
            'pickupType' => (string)$this->setting('pickupType', 'DROPOFF_AT_FEDEX_LOCATION'),
            'shippingChargesPayment' => [
                'paymentType' => 'SENDER',
                'payor' => ['responsibleParty' => ['accountNumber' => $this->account()]],
            ],
            'labelSpecification' => ['imageType' => $imageType, 'labelStockType' => $stock],
            'totalWeight' => self::totalWeight($request),
            'totalPackageCount' => count($request->parcels),
            'requestedPackageLineItems' => array_map(fn(Parcel $p) => $this->lineItem($p, $request, true), $request->parcels),
        ];

        $special = [];

        if ($request->isReturn) {
            $special['specialServiceTypes'][] = 'RETURN_SHIPMENT';
            $special['returnShipmentDetail'] = [
                'returnType' => 'PRINT_RETURN_LABEL',
                'rma' => ['reason' => mb_substr(trim('Return ' . $request->reference), 0, 60)],
            ];
        } else {
            if (in_array(Accessorial::SATURDAY_DELIVERY, $request->accessorials, true)) {
                $special['specialServiceTypes'][] = 'SATURDAY_DELIVERY';
            }

            if ($request->pointId !== '') {
                $special['specialServiceTypes'][] = 'HOLD_AT_LOCATION';
                $special['holdAtLocationDetail'] = ['locationId' => $request->pointId];
            }
        }

        if ($special !== []) {
            $shipment['shipmentSpecialServices'] = $special;
        }

        if ($request->isInternational()) {
            $shipment['customsClearanceDetail'] = $this->customs($request);
        }

        $response = $this->transport()->post('/ship/v1/shipments', [
            'labelResponseOptions' => 'LABEL',
            'accountNumber' => $this->account(),
            'requestedShipment' => $shipment,
        ]);

        if (!$response->ok()) {
            return ShipmentResult::fromResponse($response);
        }

        $errors = $response->at('errors');

        if (is_array($errors) && $errors !== []) {
            return ShipmentResult::rejected($response->errorMessage());
        }

        $transaction = (array)($response->at('output.transactionShipments.0') ?? []);
        $pieces = array_values(array_filter((array)($transaction['pieceResponses'] ?? []), 'is_array'));
        $master = (string)($transaction['masterTrackingNumber'] ?? $transaction['completedShipmentDetail']['masterTrackingId']['trackingNumber'] ?? '');

        if ($pieces === []) {
            return ShipmentResult::uncertain($master !== ''
                ? Craft::t('carrier', 'FedEx created shipment {id} but returned no packages. Check it in FedEx before buying again.', ['id' => $master])
                : Craft::t('carrier', 'FedEx answered without a shipment. It may still have been created; check FedEx before buying again.'));
        }

        $numbers = [];
        $labels = [];

        foreach ($pieces as $i => $piece) {
            $number = (string)($piece['trackingNumber'] ?? '');
            $data = self::document((array)($piece['packageDocuments'] ?? []), 'LABEL', $format);

            if ($number === '' || $data === null) {
                return ShipmentResult::uncertain(Craft::t('carrier', 'FedEx created shipment {id} but package {n} came back without a tracking number or a usable label. Cancel or reprint it in FedEx.', [
                    'id' => $master ?: '?',
                    'n' => $i + 1,
                ]));
            }

            $numbers[] = $number;
            $labels[] = new Label([
                'parcelIndex' => $i,
                'trackingNumber' => $number,
                'format' => $format,
                'kind' => $request->isReturn ? 'return' : 'label',
                'data' => $data,
            ]);
        }

        foreach ((array)($transaction['shipmentDocuments'] ?? []) as $document) {
            $bytes = is_array($document) ? base64_decode((string)($document['encodedLabel'] ?? ''), true) : false;

            if (is_string($bytes) && str_starts_with($bytes, '%PDF')) {
                $labels[] = new Label(['format' => LabelFormat::PDF_LETTER, 'kind' => 'invoice', 'data' => $bytes]);
            }
        }

        $master = $master !== '' ? $master : $numbers[0];
        $result = ShipmentResult::ok($master, $numbers, $labels, [
            'masterTrackingNumber' => $master,
            'senderCountryCode' => strtoupper($from->countryCode),
            'shipDatestamp' => $shipDate,
            'serviceType' => $service,
            'format' => $format,
            'sandbox' => $this->isSandbox(),
        ]);

        $rating = self::pickRate((array)($transaction['completedShipmentDetail']['shipmentRating']['shipmentRateDetails'] ?? []), ['PAYOR_ACCOUNT_SHIPMENT', 'PAYOR_LIST_SHIPMENT']);

        if (isset($rating['totalNetCharge'])) {
            $result->withCharge(round((float)$rating['totalNetCharge'], 2), strtoupper((string)($rating['currency'] ?? ($request->currency ?: 'USD'))));
        }

        $result->warnings = array_merge(
            self::alerts($transaction['alerts'] ?? null),
            self::alerts($response->at('output.alerts')),
        );

        if ($service !== $request->serviceCode) {
            $result->warnings[] = Craft::t('carrier', 'Shipped as {service}, which FedEx requires for this address.', [
                'service' => self::services()[$service]['name'] ?? $service,
            ]);
        }

        return $result;
    }

    /**
     * Cancel by master tracking number, every package at once.
     */
    public function cancelShipment(ShipmentRef $shipment): ActionResult
    {
        $number = (string)($shipment->data['masterTrackingNumber'] ?? '') ?: ($shipment->shipmentId ?: (string)($shipment->trackingNumbers[0] ?? ''));

        if ($number === '') {
            return ActionResult::failed(Craft::t('carrier', 'This shipment has no FedEx tracking number to cancel.'));
        }

        $response = $this->transport()->put('/ship/v1/shipments/cancel', [
            'accountNumber' => $this->account(),
            'trackingNumber' => $number,
            'senderCountryCode' => (string)($shipment->data['senderCountryCode'] ?? 'US'),
            'deletionControl' => 'DELETE_ALL_PACKAGES',
        ]);

        if (!$response->ok()) {
            return ActionResult::failed($response->errorMessage());
        }

        if ($response->at('output.cancelledShipment') === true) {
            return ActionResult::ok($number, (string)$response->at('output.message', '') ?: null);
        }

        $alerts = self::alerts($response->at('output.alerts'));

        return ActionResult::failed((string)$response->at('output.message', '') ?: ($alerts[0] ?? Craft::t('carrier', 'FedEx did not confirm the cancellation.')));
    }

    // =========================================================================
    // Tracking
    // =========================================================================

    /**
     * Up to 30 numbers in one call. The answer can be HTTP 200 with a per-number error inside.
     *
     * @return TrackingInfo[]
     */
    public function track(array $trackingNumbers): array
    {
        if ($trackingNumbers === []) {
            return [];
        }

        $response = $this->transport()->post('/track/v1/trackingnumbers', [
            'includeDetailedScans' => true,
            'trackingInfo' => array_map(
                static fn(string $number) => ['trackingNumberInfo' => ['trackingNumber' => $number]],
                array_values($trackingNumbers),
            ),
        ]);

        if (!$response->ok()) {
            return [];
        }

        $out = [];

        foreach ((array)$response->at('output.completeTrackResults', []) as $complete) {
            if (!is_array($complete)) {
                continue;
            }

            $result = $complete['trackResults'][0] ?? null;

            if (!is_array($result)) {
                continue;
            }

            $number = (string)($complete['trackingNumber'] ?? $result['trackingNumberInfo']['trackingNumber'] ?? '');

            if ($number === '') {
                continue;
            }

            if (!empty($result['error'])) {
                if (stripos((string)($result['error']['code'] ?? ''), 'NOTFOUND') !== false) {
                    $out[] = new TrackingInfo(['trackingNumber' => $number, 'notFound' => true]);
                }

                continue;
            }

            $out[] = $this->trackingInfo($number, $result);
        }

        return $out;
    }

    private function trackingInfo(string $number, array $result): TrackingInfo
    {
        $events = [];

        foreach ((array)($result['scanEvents'] ?? []) as $scan) {
            if (!is_array($scan)) {
                continue;
            }

            $code = self::scanCode($scan);
            $location = $scan['scanLocation'] ?? [];
            $description = (string)($scan['eventDescription'] ?? '');

            if (!empty($scan['exceptionDescription'])) {
                $description = trim($description . ' — ' . $scan['exceptionDescription'], ' —');
            }

            $events[] = new TrackingEvent([
                'occurredAt' => self::parseDate($scan['date'] ?? null, 'America/Chicago'),
                'status' => in_array($code, self::INFORMATIONAL, true) ? TrackingStatus::IN_TRANSIT : self::mapStatus($code, self::STATUS_MAP),
                'code' => (string)($scan['eventType'] ?? $code),
                'description' => $description,
                'location' => trim(implode(', ', array_filter([
                    (string)($location['city'] ?? ''),
                    (string)($location['stateOrProvinceCode'] ?? ''),
                    (string)($location['countryCode'] ?? ''),
                ]))),
            ]);
        }

        $latest = $result['latestStatusDetail'] ?? [];
        $code = strtoupper((string)($latest['derivedCode'] ?? '') ?: (string)($latest['code'] ?? ''));

        if ($code === '') {
            $status = $events === [] ? TrackingStatus::UNKNOWN : $events[0]->status;
        } elseif (in_array($code, self::INFORMATIONAL, true)) {
            // A delivery-change request moves nothing; keep the last real scan's status.
            $status = TrackingStatus::IN_TRANSIT;

            foreach ((array)($result['scanEvents'] ?? []) as $scan) {
                $scanCode = is_array($scan) ? self::scanCode($scan) : '';

                if ($scanCode !== '' && !in_array($scanCode, self::INFORMATIONAL, true)) {
                    $status = self::mapStatus($scanCode, self::STATUS_MAP);
                    break;
                }
            }
        } else {
            $status = self::mapStatus($code, self::STATUS_MAP);
        }

        $dates = [];

        foreach ((array)($result['dateAndTimes'] ?? []) as $date) {
            if (is_array($date) && !empty($date['type'])) {
                $dates[strtoupper((string)$date['type'])] = self::parseDate($date['dateTime'] ?? null, 'America/Chicago');
            }
        }

        $estimated = $dates['ESTIMATED_DELIVERY']
            ?? self::parseDate($result['estimatedDeliveryTimeWindow']['window']['ends'] ?? null, 'America/Chicago')
            ?? self::parseDate($result['standardTransitTimeWindow']['window']['ends'] ?? null, 'America/Chicago');

        return new TrackingInfo([
            'trackingNumber' => $number,
            'status' => $status,
            'statusText' => (string)($latest['statusByLocale'] ?? $latest['description'] ?? ''),
            'estimatedDelivery' => $status === TrackingStatus::DELIVERED ? null : $estimated,
            'deliveredAt' => $status === TrackingStatus::DELIVERED ? ($dates['ACTUAL_DELIVERY'] ?? $events[0]->occurredAt ?? null) : null,
            'signedBy' => (string)($result['deliveryDetails']['receivedByName'] ?? ''),
            'events' => $events,
        ]);
    }

    /**
     * A scan's own `eventType` (`OD`, `AR`, `OC`) says more than its `derivedStatusCode`, which
     * folds "on the van" and "label created" into broader buckets. The derived code is the
     * fallback for event types this map does not know.
     */
    private static function scanCode(array $scan): string
    {
        $type = strtoupper((string)($scan['eventType'] ?? ''));

        if ($type !== '' && (isset(self::STATUS_MAP[$type]) || in_array($type, self::INFORMATIONAL, true))) {
            return $type;
        }

        return strtoupper((string)($scan['derivedStatusCode'] ?? '')) ?: $type;
    }

    // =========================================================================
    // Locations
    // =========================================================================

    /**
     * FedEx Office stores and the retail counters (Walgreens, Dollar General, authorised ship
     * centres) that hold parcels for collection. Searched by address; FedEx's coordinate search
     * is not used because its request shape is not documented in the research.
     */
    public function pickupPoints(PointQuery $query): PointPage
    {
        if ($query->postcode === '' && $query->city === '') {
            return PointPage::failed(Craft::t('carrier', 'Give a ZIP code or a city to search for FedEx locations.'));
        }

        $types = [];

        if ($query->types === [] || in_array(PickupPoint::TYPE_STORE, $query->types, true)) {
            $types[] = 'FEDEX_OFFICE';
        }

        if ($query->types === [] || in_array(PickupPoint::TYPE_SHOP, $query->types, true)) {
            array_push($types, 'FEDEX_AUTHORIZED_SHIP_CENTER', 'FEDEX_ONSITE');
        }

        if ($types === []) {
            return PointPage::of([]);
        }

        $response = $this->transport()->post('/location/v1/locations', [
            'locationSearchCriterion' => 'ADDRESS',
            'location' => ['address' => array_filter([
                'streetLines' => $query->street !== '' ? [$query->street] : null,
                'city' => $query->city,
                'postalCode' => $query->postcode,
                'countryCode' => strtoupper($query->countryCode),
            ], static fn($v) => $v !== null && $v !== '')],
            'locationTypes' => $types,
            'sort' => ['criteria' => 'DISTANCE', 'order' => 'ASCENDING'],
            'resultsRequested' => max(1, min(50, $query->limit)),
        ]);

        if (!$response->ok()) {
            return PointPage::failed($response->errorMessage());
        }

        $points = [];

        foreach ((array)$response->at('output.locationDetailList', []) as $location) {
            if (!is_array($location) || empty($location['locationId'])) {
                continue;
            }

            $address = $location['contactAndAddress']['address'] ?? [];
            $contact = $location['contactAndAddress']['contact'] ?? [];
            $type = strtoupper((string)($location['locationType'] ?? ''));
            $name = trim((string)($contact['companyName'] ?? '') ?: (string)($contact['personName'] ?? ''));
            $distance = $location['distance']['value'] ?? null;
            $unit = strtoupper((string)($location['distance']['units'] ?? 'MI'));
            $coordinates = $location['geoPositionalCoordinates'] ?? [];

            $points[] = new PickupPoint([
                'id' => (string)$location['locationId'],
                'type' => $type === 'FEDEX_OFFICE' ? PickupPoint::TYPE_STORE : PickupPoint::TYPE_SHOP,
                'name' => $name !== '' ? $name : 'FedEx ' . $location['locationId'],
                'street' => implode(', ', array_filter((array)($address['streetLines'] ?? []), 'is_string')),
                'city' => (string)($address['city'] ?? ''),
                'postcode' => (string)($address['postalCode'] ?? ''),
                'countryCode' => strtoupper((string)($address['countryCode'] ?? $query->countryCode)),
                'latitude' => is_numeric($coordinates['latitude'] ?? null) ? (float)$coordinates['latitude'] : null,
                'longitude' => is_numeric($coordinates['longitude'] ?? null) ? (float)$coordinates['longitude'] : null,
                'codAllowed' => false,
                'distanceKm' => is_numeric($distance) ? round((float)$distance * ($unit === 'KM' ? 1 : 1.609344), 2) : null,
                'extra' => array_filter([
                    'region' => (string)($address['stateOrProvinceCode'] ?? ''),
                    'locationType' => $type,
                    'storeHours' => $location['storeHours'] ?? null,
                ]),
            ]);
        }

        return PointPage::of($points);
    }

    // =========================================================================
    // Pickups and address validation
    // =========================================================================

    public function requestPickup(PickupRequest $request): ActionResult
    {
        $address = $request->address;

        if ($address === null) {
            return ActionResult::failed(Craft::t('carrier', 'A FedEx pickup needs an address.'));
        }

        $date = $request->date ?? new \DateTime('tomorrow');
        $body = [
            'associatedAccountNumber' => $this->account(),
            'originDetail' => [
                'pickupLocation' => $this->party($address),
                'readyDateTimestamp' => $date->format('Y-m-d') . 'T' . self::time($request->readyTime, '09:00') . ':00Z',
                'customerCloseTime' => self::time($request->closeTime, '17:00') . ':00',
            ],
            'carrierCode' => (string)$this->setting('pickupCarrier', 'FDXG'),
            'totalWeight' => [
                'units' => 'LB',
                'value' => max(0.1, Units::gramsToLb(max(1, $request->totalWeightG), 1)),
            ],
            'packageCount' => max(1, $request->parcelCount),
        ];

        if ($request->instructions !== '') {
            $body['remarks'] = mb_substr($request->instructions, 0, 60);
        }

        $response = $this->transport()->post('/pickup/v1/pickups', $body);

        if (!$response->ok()) {
            return ActionResult::failed($response->errorMessage());
        }

        $code = (string)$response->at('output.pickupConfirmationCode', '');

        if ($code === '') {
            return ActionResult::failed(Craft::t('carrier', 'FedEx did not return a pickup confirmation number.'));
        }

        return ActionResult::ok($code, Craft::t('carrier', 'FedEx pickup {code} booked for {date}.', [
            'code' => $code,
            'date' => $date->format('Y-m-d'),
        ]), ['location' => (string)$response->at('output.location', '')]);
    }

    public function validateAddress(Address $address): AddressResult
    {
        $response = $this->transport()->post('/address/v1/addresses/resolve', [
            'addressesToValidate' => [['address' => $this->address($address)]],
        ]);

        if (!$response->ok()) {
            return new AddressResult(error: $response->errorMessage());
        }

        $resolved = $response->at('output.resolvedAddresses.0');

        if (!is_array($resolved)) {
            return new AddressResult(error: Craft::t('carrier', 'FedEx returned no address validation result.'));
        }

        $attributes = (array)($resolved['attributes'] ?? []);
        $truthy = static fn($v) => $v === true || strtolower((string)$v) === 'true';
        $valid = array_key_exists('DPV', $attributes) ? $truthy($attributes['DPV']) : $truthy($attributes['Resolved'] ?? false);
        $classification = strtoupper((string)($resolved['classification'] ?? ''));
        $residential = match ($classification) {
            'RESIDENTIAL' => true,
            'BUSINESS' => false,
            default => null,
        };

        $lines = array_values(array_filter((array)($resolved['streetLinesToken'] ?? []), 'is_string'));
        $suggestion = new Address([
            'name' => $address->name,
            'company' => $address->company,
            'street1' => $lines[0] ?? $address->streetLine(),
            'street2' => implode(', ', array_slice($lines, 1)),
            'city' => (string)($resolved['city'] ?? $address->city),
            'region' => (string)($resolved['stateOrProvinceCode'] ?? $address->region),
            'postcode' => (string)($resolved['postalCode'] ?? $address->postcode),
            'countryCode' => strtoupper((string)($resolved['countryCode'] ?? $address->countryCode)),
            'phone' => $address->phone,
            'email' => $address->email,
            'residential' => $residential ?? $address->residential,
        ]);

        $messages = [];

        foreach ((array)($resolved['customerMessages'] ?? []) as $message) {
            $text = is_array($message) ? (string)($message['message'] ?? $message['code'] ?? '') : (string)$message;

            if ($text !== '') {
                $messages[] = $text;
            }
        }

        return new AddressResult(
            valid: $valid,
            suggestions: [$suggestion],
            residential: $residential,
            messages: $messages,
        );
    }

    // =========================================================================
    // Request building
    // =========================================================================

    /**
     * [shipper, recipient]. A return makes the customer the shipper.
     *
     * @return Address[]
     */
    private function parties(ShipmentRequest $request): array
    {
        $merchant = $request->shipFrom ?? new Address();
        $customer = $request->shipTo ?? new Address();

        return $request->isReturn ? [$customer, $merchant] : [$merchant, $customer];
    }

    /**
     * A return goes back to the merchant's dock, and a Hold at Location parcel to a store counter;
     * neither is a residential delivery, and calling it one would turn Ground into Home Delivery.
     */
    private function isResidential(ShipmentRequest $request, Address $to): bool
    {
        if ($request->isReturn || $request->pointId !== '') {
            return false;
        }

        return $to->residential || in_array(Accessorial::RESIDENTIAL_DELIVERY, $request->accessorials, true);
    }

    /**
     * The service that goes on the wire. The two Grounds are one product to a merchant and two
     * to FedEx, which refuses the wrong one for the address type.
     */
    private function wireService(string $code, ShipmentRequest $request, bool $residential): string
    {
        if (in_array($code, self::GROUND_PAIR, true) && !$request->isInternational()) {
            return $residential ? 'GROUND_HOME_DELIVERY' : 'FEDEX_GROUND';
        }

        return $code;
    }

    private function address(Address $address): array
    {
        return array_filter([
            'streetLines' => array_values(array_map(
                static fn(string $line) => mb_substr($line, 0, 35),
                array_slice(array_filter([$address->streetLine(), $address->street2]), 0, 3),
            )),
            'city' => $address->city,
            'stateOrProvinceCode' => $address->region,
            'postalCode' => $address->postcode,
            'countryCode' => strtoupper($address->countryCode),
        ], static fn($v) => $v !== '' && $v !== []);
    }

    private function party(Address $address): array
    {
        $contact = array_filter([
            'personName' => $address->contactName(),
            'companyName' => $address->company,
            'phoneNumber' => self::phone($address),
            'emailAddress' => $address->email,
        ], static fn($v) => $v !== '');

        return ['contact' => $contact, 'address' => $this->address($address)];
    }

    private function lineItem(Parcel $parcel, ShipmentRequest $request, bool $forShip): array
    {
        $item = [
            'weight' => ['units' => 'LB', 'value' => max(0.1, Units::gramsToLb($parcel->weightG, 1))],
        ];

        if ($parcel->hasDimensions()) {
            $item['dimensions'] = [
                'length' => self::inches($parcel->lengthMm),
                'width' => self::inches($parcel->widthMm),
                'height' => self::inches($parcel->heightMm),
                'units' => 'IN',
            ];
        }

        if ($parcel->value > 0 && $this->boolSetting('declareValue')) {
            $item['declaredValue'] = ['amount' => round($parcel->value, 2), 'currency' => $request->currency ?: 'USD'];
        }

        $signature = match (true) {
            $request->isReturn => null,
            in_array(Accessorial::ADULT_SIGNATURE, $request->accessorials, true) => 'ADULT',
            in_array(Accessorial::SIGNATURE, $request->accessorials, true) => 'DIRECT',
            default => null,
        };

        if ($signature !== null) {
            $item['packageSpecialServices'] = [
                'specialServiceTypes' => ['SIGNATURE_OPTION'],
                'signatureOptionType' => $signature,
            ];
        }

        if ($forShip && $request->reference !== '') {
            $item['customerReferences'] = [['customerReferenceType' => 'CUSTOMER_REFERENCE', 'value' => $request->reference]];
        }

        return $item;
    }

    private function dutiesPayer(): string
    {
        return $this->setting('dutiesPayer', 'RECIPIENT') === 'SENDER' ? 'SENDER' : 'RECIPIENT';
    }

    private function commodities(ShipmentRequest $request): array
    {
        $currency = $request->currency ?: 'USD';
        $origin = strtoupper((string)$request->shipFrom?->countryCode);

        return array_map(static fn($item) => array_filter([
            'description' => mb_substr($item->description !== '' ? $item->description : 'Merchandise', 0, 450),
            'countryOfManufacture' => strtoupper($item->originCountry !== '' ? $item->originCountry : $origin),
            'quantity' => max(1, $item->quantity),
            'quantityUnits' => 'PCS',
            'unitPrice' => ['amount' => round($item->unitValue, 2), 'currency' => $currency],
            'customsValue' => ['amount' => round($item->unitValue * max(1, $item->quantity), 2), 'currency' => $currency],
            'weight' => ['units' => 'LB', 'value' => max(0.1, Units::gramsToLb(max(1, $item->unitWeightG) * max(1, $item->quantity), 1))],
            'harmonizedCode' => preg_replace('/\D/', '', $item->hsCode),
        ], static fn($v) => $v !== ''), $request->customsItems);
    }

    private function customs(ShipmentRequest $request): array
    {
        $currency = $request->currency ?: 'USD';
        $commodities = $this->commodities($request);
        $total = array_sum(array_map(static fn(array $c) => $c['customsValue']['amount'], $commodities));

        return [
            'dutiesPayment' => ['paymentType' => $this->dutiesPayer()],
            'isDocumentOnly' => false,
            'commercialInvoice' => [
                'shipmentPurpose' => $request->isReturn ? 'NOT_SOLD' : 'SOLD',
                'termsOfSale' => $this->dutiesPayer() === 'SENDER' ? 'DDP' : 'DDU',
            ],
            'commodities' => $commodities,
            'totalCustomsValue' => ['amount' => round($total, 2), 'currency' => $currency],
        ];
    }

    /** `YYYY-MM-DD`, never in the past: FedEx refuses a ship date before today. */
    private function shipDate(ShipmentRequest $request): string
    {
        $today = new \DateTime('today');

        return ($request->shipDate && $request->shipDate >= $today ? $request->shipDate : $today)->format('Y-m-d');
    }

    // =========================================================================
    // Labels
    // =========================================================================

    /** @return string[] [imageType, labelStockType] */
    private static function labelSpec(string $format): array
    {
        return match ($format) {
            LabelFormat::ZPL => ['ZPLII', 'STOCK_4X6'],
            LabelFormat::PNG => ['PNG', 'PAPER_4X6'],
            LabelFormat::PDF_LETTER => ['PDF', 'PAPER_85X11_TOP_HALF_LABEL'],
            default => ['PDF', 'PAPER_4X6'],
        };
    }

    /**
     * The decoded bytes of the first document of a content type, or null when they are missing or
     * not the format that was asked for.
     */
    private static function document(array $documents, string $contentType, string $format): ?string
    {
        foreach ($documents as $document) {
            if (!is_array($document) || strtoupper((string)($document['contentType'] ?? $contentType)) !== $contentType) {
                continue;
            }

            $bytes = base64_decode((string)($document['encodedLabel'] ?? ''), true);

            if (is_string($bytes) && LabelFormat::bytesMatch($format, $bytes)) {
                return $bytes;
            }
        }

        return null;
    }

    // =========================================================================
    // Small helpers
    // =========================================================================

    /**
     * The rate detail of the preferred type, else the first one.
     *
     * @param string[] $types in order of preference
     */
    private static function pickRate(array $details, array $types): array
    {
        $details = array_values(array_filter($details, 'is_array'));

        foreach ($types as $type) {
            foreach ($details as $detail) {
                if (strtoupper((string)($detail['rateType'] ?? '')) === $type) {
                    return $detail;
                }
            }
        }

        return $details[0] ?? [];
    }

    private static function totalWeight(ShipmentRequest $request): float
    {
        return max(0.1, Units::gramsToLb($request->totalWeightG(), 1));
    }

    /** Whole inches, rounded up, within FedEx's 1–999. */
    private static function inches(int $mm): int
    {
        return (int)min(999, max(1, Units::mmToIn($mm, 0)));
    }

    /** `ONE_DAY`, `THREE_DAYS`… as a number. */
    private static function days(mixed $value): ?int
    {
        if (is_numeric($value)) {
            return (int)$value;
        }

        $word = strtoupper(explode('_', (string)$value)[0]);

        return self::DAY_WORDS[$word] ?? null;
    }

    private static function isoDate(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) ? substr($value, 0, 10) : null;
    }

    /** Digits only; a North American number loses its leading 1. */
    private static function phone(Address $address): string
    {
        $digits = (string)preg_replace('/\D/', '', $address->phone);

        if (in_array(strtoupper($address->countryCode), ['US', 'CA', 'PR'], true) && strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }

        return mb_substr($digits, 0, 15);
    }

    private static function time(string $value, string $default): string
    {
        return preg_match('/^(\d{1,2}):?(\d{2})/', trim($value), $m) ? sprintf('%02d:%02d', $m[1], $m[2]) : $default;
    }

    /** @return string[] */
    private static function alerts(mixed $alerts): array
    {
        $out = [];

        foreach (is_array($alerts) ? $alerts : [] as $alert) {
            $text = is_array($alert) ? trim((string)($alert['message'] ?? '')) : '';

            if ($text !== '') {
                $out[] = $text;
            }
        }

        return $out;
    }
}
