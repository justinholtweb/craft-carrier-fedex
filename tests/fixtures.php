<?php
/**
 * Recorded happy-path answers for the FedEx carrier, for Carrier's conformance suite.
 *
 * Each body follows the response schemas in the FedEx OpenAPI JSON (RateQuotes, Shipment,
 * TrackingNumbers, Locations, Pickup, Addresses, APIAuthorization), trimmed to the fields the
 * carrier reads. Numbers are real JSON numbers here, unlike UPS. The ship answer returns label
 * bytes in whichever `imageType` the request asked for, one piece per requested package.
 */

use justinholtweb\carrier\base\Response;
use justinholtweb\carrier\helpers\Pdf;

return [
    'fedex' => static function(string $method, string $url, array $options): Response {
        $path = (string)parse_url($url, PHP_URL_PATH);
        $body = $options['json'] ?? [];

        if ($path === '/oauth/token') {
            return Response::json(200, [
                'access_token' => 'eyJhbGciOiJSUzI1NiJ9.fixture',
                'token_type' => 'bearer',
                'expires_in' => 3599,
                'scope' => 'CXS',
            ]);
        }

        if ($path === '/rate/v1/rates/quotes') {
            $reply = static fn(string $service, string $name, float $account, float $list, string $days, string $date) => [
                'serviceType' => $service,
                'serviceName' => $name,
                'packagingType' => 'YOUR_PACKAGING',
                'ratedShipmentDetails' => [
                    ['rateType' => 'ACCOUNT', 'ratedWeightMethod' => 'ACTUAL', 'totalBaseCharge' => $account - 2.1, 'totalNetCharge' => $account, 'totalNetFedExCharge' => $account, 'shipmentRateDetail' => ['currency' => 'USD'], 'currency' => 'USD'],
                    ['rateType' => 'LIST', 'ratedWeightMethod' => 'ACTUAL', 'totalBaseCharge' => $list - 2.1, 'totalNetCharge' => $list, 'totalNetFedExCharge' => $list, 'shipmentRateDetail' => ['currency' => 'USD'], 'currency' => 'USD'],
                ],
                'operationalDetail' => ['transitTime' => $days, 'deliveryDate' => $date . 'T20:00:00'],
                'commit' => ['daysInTransit' => $days, 'dateDetail' => ['dayOfWeek' => 'TUE', 'dayFormat' => $date . 'T20:00:00']],
                'customerMessages' => [],
            ];

            $replies = [
                $reply('FEDEX_GROUND', 'FedEx Ground', 15.84, 18.66, 'ONE_DAY', '2026-10-06'),
                $reply('GROUND_HOME_DELIVERY', 'FedEx Home Delivery', 17.93, 21.05, 'ONE_DAY', '2026-10-06'),
                $reply('FEDEX_2_DAY', 'FedEx 2Day', 34.20, 41.75, 'TWO_DAYS', '2026-10-07'),
                $reply('PRIORITY_OVERNIGHT', 'FedEx Priority Overnight', 66.10, 82.40, 'ONE_DAY', '2026-10-06'),
            ];

            $requested = $body['requestedShipment']['serviceType'] ?? null;

            if ($requested !== null) {
                $replies = array_values(array_filter($replies, static fn($r) => $r['serviceType'] === $requested))
                    ?: [$reply($requested, $requested, 25.00, 30.00, 'THREE_DAYS', '2026-10-08')];
            }

            return Response::json(200, [
                'transactionId' => 'fixture-rate',
                'output' => [
                    'rateReplyDetails' => $replies,
                    'quoteDate' => '2026-10-05',
                    'encoded' => false,
                    'alerts' => [['code' => 'VIRTUAL.RESPONSE', 'alertType' => 'NOTE', 'message' => 'This is a Virtual Response.']],
                ],
            ]);
        }

        if ($path === '/ship/v1/shipments') {
            $imageType = $body['requestedShipment']['labelSpecification']['imageType'] ?? 'PDF';
            $items = $body['requestedShipment']['requestedPackageLineItems'] ?? [[]];
            $pieces = [];

            foreach (array_values($items) as $i => $item) {
                $number = sprintf('79498098%04d', 1000 + $i);
                $bytes = match ($imageType) {
                    'ZPLII' => "^XA^FO50,50^A0N,40,40^FDFEDEX^FS^FO50,120^BCN,100,Y,N,N^FD$number^FS^XZ",
                    'PNG' => "\x89PNG\r\n\x1a\n" . str_repeat("\0", 16),
                    default => Pdf::text(['FEDEX', $number]),
                };

                $pieces[] = [
                    'masterTrackingNumber' => '794980981000',
                    'trackingNumber' => $number,
                    'deliveryDatestamp' => '2026-10-06',
                    'netChargeAmount' => 7.92,
                    'packageDocuments' => [[
                        'contentType' => 'LABEL',
                        'copiesToPrint' => 1,
                        'encodedLabel' => base64_encode($bytes),
                        'docType' => $imageType,
                    ]],
                    'customerReferences' => [],
                ];
            }

            return Response::json(200, [
                'transactionId' => 'fixture-ship',
                'output' => [
                    'transactionShipments' => [[
                        'serviceType' => $body['requestedShipment']['serviceType'] ?? 'FEDEX_GROUND',
                        'shipDatestamp' => $body['requestedShipment']['shipDatestamp'] ?? '2026-10-05',
                        'serviceName' => 'FedEx Ground',
                        'masterTrackingNumber' => '794980981000',
                        'pieceResponses' => $pieces,
                        'completedShipmentDetail' => [
                            'usDomestic' => true,
                            'carrierCode' => 'FDXG',
                            'masterTrackingId' => ['trackingIdType' => 'FEDEX', 'trackingNumber' => '794980981000'],
                            'shipmentRating' => [
                                'actualRateType' => 'PAYOR_ACCOUNT_SHIPMENT',
                                'shipmentRateDetails' => [
                                    ['rateType' => 'PAYOR_ACCOUNT_SHIPMENT', 'totalNetCharge' => 15.84, 'totalBaseCharge' => 13.74, 'currency' => 'USD'],
                                    ['rateType' => 'PAYOR_LIST_SHIPMENT', 'totalNetCharge' => 18.66, 'totalBaseCharge' => 16.56, 'currency' => 'USD'],
                                ],
                            ],
                        ],
                        'shipmentDocuments' => [],
                        'alerts' => [],
                    ]],
                    'alerts' => [],
                ],
            ]);
        }

        if ($path === '/ship/v1/shipments/cancel') {
            return Response::json(200, [
                'transactionId' => 'fixture-cancel',
                'output' => ['cancelledShipment' => true, 'cancelledHistory' => true, 'successMessage' => 'Success'],
            ]);
        }

        if ($path === '/track/v1/trackingnumbers') {
            $results = [];

            foreach ($body['trackingInfo'] ?? [] as $info) {
                $number = (string)($info['trackingNumberInfo']['trackingNumber'] ?? '');
                $scan = static fn(string $date, string $type, string $derived, string $description, string $city) => [
                    'date' => $date,
                    'derivedStatus' => $description,
                    'scanLocation' => ['city' => $city, 'stateOrProvinceCode' => 'NC', 'countryCode' => 'US', 'postalCode' => '28202'],
                    'eventDescription' => $description,
                    'eventType' => $type,
                    'derivedStatusCode' => $derived,
                    'exceptionCode' => '',
                    'exceptionDescription' => '',
                ];

                $results[] = [
                    'trackingNumber' => $number,
                    'trackResults' => [[
                        'trackingNumberInfo' => ['trackingNumber' => $number, 'carrierCode' => 'FDXG', 'trackingNumberUniqueId' => '2460~' . $number . '~FDEG'],
                        'latestStatusDetail' => [
                            'code' => 'DL',
                            'derivedCode' => 'DL',
                            'statusByLocale' => 'Delivered',
                            'description' => 'Delivered',
                            'scanLocation' => ['city' => 'CHARLOTTE', 'stateOrProvinceCode' => 'NC', 'countryCode' => 'US'],
                        ],
                        'dateAndTimes' => [
                            ['type' => 'ACTUAL_DELIVERY', 'dateTime' => '2026-10-06T14:32:00-04:00'],
                            ['type' => 'ACTUAL_PICKUP', 'dateTime' => '2026-10-05T17:30:00-04:00'],
                            ['type' => 'SHIP', 'dateTime' => '2026-10-05T00:00:00-06:00'],
                        ],
                        'deliveryDetails' => ['receivedByName' => 'A.KOVAC', 'deliveryAttempts' => '0'],
                        'scanEvents' => [
                            $scan('2026-10-06T14:32:00-04:00', 'DL', 'DL', 'Delivered', 'CHARLOTTE'),
                            $scan('2026-10-06T08:10:00-04:00', 'OD', 'IT', 'On FedEx vehicle for delivery', 'CHARLOTTE'),
                            $scan('2026-10-06T04:30:00-04:00', 'AR', 'IT', 'At local FedEx facility', 'CHARLOTTE'),
                            $scan('2026-10-05T17:30:00-04:00', 'PU', 'PU', 'Picked up', 'CHARLOTTE'),
                            $scan('2026-10-05T10:15:00-04:00', 'OC', 'IN', 'Shipment information sent to FedEx', ''),
                        ],
                    ]],
                ];
            }

            return Response::json(200, ['transactionId' => 'fixture-track', 'output' => ['completeTrackResults' => $results]]);
        }

        if ($path === '/location/v1/locations') {
            $location = static fn(string $id, string $type, string $company, string $street, float $lat, float $lng, float $miles) => [
                'locationId' => $id,
                'locationType' => $type,
                'distance' => ['units' => 'MI', 'value' => $miles],
                'contactAndAddress' => [
                    'contact' => ['companyName' => $company],
                    'address' => ['streetLines' => [$street], 'city' => 'CHARLOTTE', 'stateOrProvinceCode' => 'NC', 'postalCode' => '28202', 'countryCode' => 'US'],
                ],
                'geoPositionalCoordinates' => ['latitude' => $lat, 'longitude' => $lng],
                'storeHours' => [['dayofweek' => 'MON', 'operationalHoursType' => 'OPEN_BY_HOURS', 'operationalHours' => ['begins' => '08:00:00', 'ends' => '20:00:00']]],
            ];

            return Response::json(200, [
                'transactionId' => 'fixture-locations',
                'output' => [
                    'totalResults' => 2,
                    'resultsReturned' => 2,
                    'locationDetailList' => [
                        $location('CLTO', 'FEDEX_OFFICE', 'FedEx Office Print & Ship Center', '227 W Trade St', 35.2280, -80.8448, 0.4),
                        $location('CLTWG', 'FEDEX_ONSITE', 'Walgreens', '301 S Tryon St', 35.2253, -80.8471, 0.6),
                    ],
                ],
            ]);
        }

        if ($path === '/pickup/v1/pickups') {
            return Response::json(200, [
                'transactionId' => 'fixture-pickup',
                'output' => ['pickupConfirmationCode' => '7', 'location' => 'CLTA'],
            ]);
        }

        if ($path === '/address/v1/addresses/resolve') {
            return Response::json(200, [
                'transactionId' => 'fixture-address',
                'output' => ['resolvedAddresses' => [[
                    'streetLinesToken' => ['100 N TRYON ST'],
                    'city' => 'CHARLOTTE',
                    'stateOrProvinceCode' => 'NC',
                    'postalCode' => '28202-4000',
                    'countryCode' => 'US',
                    'classification' => 'BUSINESS',
                    'attributes' => ['DPV' => 'true', 'Resolved' => 'true', 'Matched' => 'true'],
                    'customerMessages' => [],
                ]]],
            ]);
        }

        return Response::json(404, [
            'transactionId' => 'fixture-404',
            'errors' => [['code' => 'NOT.FOUND.ERROR', 'message' => 'The resource you requested is no longer available: ' . $path]],
        ]);
    },
];
