<?php

namespace App\Assistants;

use App\GeonamesCountry;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;

class RhenusPdfAssistant extends PdfClient
{
    public static function validateFormat (array $lines) {
        return $lines[0] == "Transport instruction"
            && $lines[2] == "Rhenus Logistics Ltd"
            && $lines[17] == "Email:";
    }

    public function processLines (array $lines, ?string $attachment_filename = null) {
        /*Customer Information Start*/
        $companyInfo                = array_find_key($lines, fn($l) => $l == "Invoicing address");
        $email                      = array_find_key($lines, fn($l) => $l == "Best regards");
        $emailAddress               = str_contains($lines[$email+8], '@') ? $email+8 : $email + 5;
        $companyPostalCodeAndCity   = $this->extractCityandPostalCode($lines[$companyInfo+4]);

        $customer = [
            'side' => 'none',
            'details' => [
                'company'           => $lines[$companyInfo+2],
                'vat_code'          => $lines[$companyInfo+9],
                'email'             => $lines[$emailAddress],
                'contact_person'    => $lines[$email+1],
                'street_address'    => $lines[$companyInfo+3],
                'city'              => $companyPostalCodeAndCity['city'],
                'country'           => 'GB',
                'postal_code'       => $companyPostalCodeAndCity['postal_code']
            ],
        ];
        /*Customer Information End*/

        /*Loading & Destination Locations, Cargo Start*/
        $loadingAndDestinationListStart = array_find_key($lines, fn($l) => $l === "Principal ref.");
        $loadingAndDestinationlistEnd   = array_find_key($lines, fn($l) => $l === "When invoicing please mention our Transport Number.");

        $allLocationList                = $this->extractLocations(
                                            array_slice($lines, $loadingAndDestinationListStart, $loadingAndDestinationlistEnd - 1 - $loadingAndDestinationListStart)
                                        );

        $loading_locations      = $allLocationList[0];
        $destination_locations  = $allLocationList[1];
        $cargos                 = $allLocationList[2];
        /*Loading & Destination Locations, Cargo End*/

        /*Order Reference Start*/
        $orderReference = array_find_key($lines, fn($l) => $l == "Principal ref.");
        $order_reference = trim($lines[$orderReference+2]);
        /*Order Reference End*/

        /*Freight Price Start*/
        $freight_price = array_find_key($lines, fn($l) => $l == "Freight cost");
        $freight_price = $lines[$freight_price+2];
        $freight_price = uncomma(explode(" ", $freight_price)[0]);
        /*Freight Price End*/
        
        /*Freight Currency Start*/
        $freight_currency = "EUR";
        /*Freight Currency End*/

        /*Transport Number Start*/
        $transport_number = array_find_key($lines, fn($l) => $l == "Transport no.");
        $transport_number = $transport_number+10;
        if(!is_int($lines[$transport_number])) {
            $transport_number = $transport_number-8;
        }
        $transport_number = $lines[$transport_number];
        /*Transport Number End*/

        /*Attachments Start*/
        $attachment_filenames = [mb_strtolower($attachment_filename ?? '')];
        /*Attachments End*/

        $data = compact(
            'customer',
            'loading_locations',
            'destination_locations',
            'attachment_filenames',
            'cargos',
            'order_reference',
            'freight_price',
            'freight_currency',
            'transport_number'
        );

        $this->createOrder($data);
    }

    public function extractCityandPostalCode(string $line)
    {
        $address  = trim(str_replace(',', '', $line));
        $city     = '';
        $postcode = '';

        if (preg_match('/\b(?:(FR|DE|BE|NL|ES|IT|LU|CH)-?)?\s*(\d{4,5})\b|\b([A-Z]{1,2}\d{1,2}[A-Z]?\s*\d[A-Z]{2})\b/i', $line, $matches)) {
            if (!empty($matches[2])) {
                $postcode = $matches[2];
                $city = trim(str_replace($matches[0], '', $line));
            } elseif (!empty($matches[3])) {
                $postcode = strtoupper($matches[3]);
                $city = trim(str_replace($matches[3], '', $line));
            }

            $city = preg_replace('/\b(FR|DE|BE|NL|ES|IT|LU|CH|GB|UK)\b[-\s]*/i', '', $city);
            $city = rtrim($city, ',');  
            $city = trim($city);
        }

        return [
            'city'        => trim($city),
            'postal_code' => $postcode
        ];
    }

    public function extractLocations(array $lines) {
        $loadingLocations       = [];
        $destinationLocations   = [];
        $cargoList              = [];
        $currentBlock           = [];
        $isCollecting           = false;
        $lastIndex              = array_key_last($lines);

        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line === '') continue;
            if ((strcasecmp($line, 'Principal ref.') === 0)) {
                if ($isCollecting && !empty($currentBlock)) {
                    $this->extractInformationsFromBlocks($currentBlock, $loadingLocations, $destinationLocations, $cargoList);
                }
                $currentBlock = [];
                $isCollecting = true;
                continue;
            }

            if ($isCollecting && $index > $lastIndex) {
                $isCollecting = false;
                if (!empty($currentBlock)) {
                    $this->extractInformationsFromBlocks($currentBlock, $loadingLocations, $destinationLocations, $cargoList);
                }
                $currentBlock = [];
                continue;
            }

            if ($isCollecting) {
                $currentBlock[] = $line;
            }
        }

        if ($isCollecting && !empty($currentBlock)) {
            $this->extractInformationsFromBlocks($currentBlock, $loadingLocations, $destinationLocations, $cargoList);
        }

        return [$loadingLocations, $destinationLocations, $cargoList];
    }

    public function extractInformationsFromBlocks(array $currentBlock, array &$loadingLocations, array &$destinationLocations, array &$cargoList) {
        $loadingInfoStart   = array_find_key($currentBlock, fn($l) => $l === "Unload place");
        $loadingInfoEnd     = '';
        foreach($currentBlock as $index => $currentLine) {
            $loadingInfoEnd     = Str::contains($currentLine, "Sender ref.:");

            if($loadingInfoEnd) {
                $loadingInfoEnd = $index;
                break;
            }
        }
        $newLoadingLocation = $this->extractCompanyInformation(
            array_slice($currentBlock, $loadingInfoStart+1, $loadingInfoEnd - 1 - $loadingInfoStart), $loadingLocations
        );

        if($newLoadingLocation != null) {
            $loadingDateAndTimeStart    = array_find_key($currentBlock, fn($l) => $l === "Requested");
            $loadingDateAndTimeEnd      = array_find_key($currentBlock, fn($l) => $l === "Latest");
            $loadingDateAndTime         = $this->extractDateAndTime(
                array_slice($currentBlock, $loadingDateAndTimeStart+1, $loadingDateAndTimeEnd - $loadingDateAndTimeStart)
            );

            $loadingLocations[] = [
                'company_address' => $newLoadingLocation,
                'time'            => $loadingDateAndTime
            ];
        }

        $destinationInfoStart   = $loadingInfoEnd;
        $destinationInfoEnd     = '';
        foreach($currentBlock as $index => $currentLine) {
            $destinationInfoEnd     = Str::contains($currentLine, "Consignee ref:");

            if($destinationInfoEnd) {
                $destinationInfoEnd = $index;
                break;
            }
        }

        $newDestinationLocation = $this->extractCompanyInformation(
            array_slice($currentBlock, $destinationInfoStart+1, $destinationInfoEnd - 1 - $destinationInfoStart)
        );

        $unloadingDateAndTimeStart    = array_find_key($currentBlock, fn($l) => $l === "Latest");
        $unloadingDateAndTimeEnd      = array_find_key($currentBlock, fn($l) => $l === "Pickup instructions");
        $dateTimeArray                = array_slice($currentBlock, $unloadingDateAndTimeStart+1, $unloadingDateAndTimeEnd - 1 - $unloadingDateAndTimeStart);

        $unloadingDateFound = false;
        foreach($dateTimeArray as $dateLine) {
            if(preg_match('/\d{1,2}-[A-Za-z]{3}-\d{4}/', $dateLine)) {
                $unloadingDateFound = true;
            }
        }

        if(!$unloadingDateFound) {
            $arrayForExtraction = [];
            if(preg_match('/\d{1,2}-[A-Za-z]{3}-\d{4}/', $currentBlock[$unloadingDateAndTimeEnd - 1])) {
                $date = $currentBlock[$unloadingDateAndTimeEnd - 1];
                $arrayForExtraction = [$currentBlock[$unloadingDateAndTimeEnd - 1]];
            }else{
                $dateLocation = array_find_key($currentBlock, fn($l) => $l === "Latest");
                $date = $currentBlock[$dateLocation - 1];
                $time = $dateTimeArray[0]." ".$dateTimeArray[1];
                $arrayForExtraction = [$date, $time];
            }
            $unloadingDateAndTime = $this->extractDateAndTime($arrayForExtraction);
        }else{
            $unloadingDateAndTime         = $this->extractDateAndTime(
                array_slice($currentBlock, $unloadingDateAndTimeStart+1, $unloadingDateAndTimeEnd - 1 - $unloadingDateAndTimeStart)
            );
        }

        $destinationLocations[] = [
            'company_address' => $newDestinationLocation,
            'time'            => $unloadingDateAndTime
        ];

        $cargoInfoStart = array_find_key($currentBlock, fn($l) => $l === "Total quantity:");
        $cargoInfoEnd   = array_key_last($currentBlock);
        $cargoList[] = $this->extractCargo(
            array_slice($currentBlock, $cargoInfoStart+1, $cargoInfoEnd - $cargoInfoStart)
        );
    }

    public function extractCompanyInformation(array $array, array $loadingLocations = []) {
        $streetLines = [];
        $companyPostalCodeAndCity = [];
        $lastIndex = array_key_last($array);
        foreach ($array as $i => $line) {
            $line = trim($line);
            if ($i === 0 || $i === $lastIndex || $line === '') continue;

            if ($this->isPostalCodeLine($line)) {
                $companyPostalCodeAndCity = $this->extractCityandPostalCode($line);
                continue;
            }
            $streetLines[] = $line;
        }

        $companyInfo                = [
                                        'company'           => $array[0],
                                        'street_address'    => implode(', ', $streetLines),
                                        'city'              => $companyPostalCodeAndCity['city'],
                                        'country'           => GeonamesCountry::getIso(ucwords(strtolower($array[$lastIndex]))),
                                        'postal_code'       => $companyPostalCodeAndCity['postal_code']
                                    ];
        if(!empty($loadingLocations)) {
            $exists                     = collect($loadingLocations)->contains(function ($location) use ($companyInfo) {
                                        if (!is_array($location)) {
                                            return false;
                                        }

                                        return $location['company_address']['company']        === $companyInfo['company']
                                            && $location['company_address']['street_address'] === $companyInfo['street_address']
                                            && $location['company_address']['city']           === $companyInfo['city']
                                            && $location['company_address']['country']        === $companyInfo['country']
                                            && $location['company_address']['postal_code']    === $companyInfo['postal_code'];
                                    });

            if (!$exists) {
                return $companyInfo;
            }else{
                return null;
            }
        }else{
            return $companyInfo;
        }
    }

    function isPostalCodeLine(string $line): bool {
        $line = trim($line);
        return preg_match('/(?:(?:FR|DE|BE|NL|ES|IT|LU|CH)?-?\s*(\d{5})|([A-Z]{1,2}\d{1,2}[A-Z]?\s*\d[A-Z]{2}))\s*(.*)|(.+?)\s+(?:(?:FR|DE|BE|NL|ES|IT|LU|CH)?-?\s*(\d{4,5})|([A-Z]{1,2}\d{1,2}[A-Z]?\s*\d[A-Z]{2}))/i', $line);

    }

    public function extractDateAndTime(array $array) {
        $date = $array[0];

        $startTime = '00:00';
        $endTime = null;

        if(isset($array[1]) && !empty(trim($array[1])) && !Str::contains($array[1], "Registered") && !preg_match('/\d{1,2}-[A-Za-z]{3}-\d{4}/', $array[1])) {
            $time = explode("-", $array[1]);
            $startTime = trim($time[0]);
            $endTime   = trim($time[1]);
        }

        $startDate = Carbon::createFromFormat('d-M-Y H:i', "$date $startTime")->toIsoString();
        $endDate = $endTime ? Carbon::createFromFormat('d-M-Y H:i', "$date $endTime")->toIsoString() : null;

        return $endDate ? ['datetime_from' => $startDate, 'datetime_to' => $endDate] : ['datetime_from' => $startDate];
    }

    public function extractCargo(array $array) {
        $data = [
            'title'         => $array[5],
            'package_type'  => 'other',
            'weight'        => empty($array[6]) ? 0 : uncomma($array[6]),
            'volume'        => empty($array[7]) ? 0 : uncomma($array[7]),
            'ldm'           => uncomma($array[8])
        ];

        $packageLength = array_find_key($array, fn($l) => $l === "Length [cm]");
        if($packageLength) {
            $data['pkg_length'] = uncomma($array[$packageLength+1]);
        }

        $packageWidth = array_find_key($array, fn($l) => $l === "Width [cm]");
        if($packageWidth) {
            $data['pkg_width'] = uncomma($array[$packageWidth+1]);
        }

        $packageHeight = array_find_key($array, fn($l) => $l === "Height [cm]");
        if($packageHeight) {
            $data['pkg_height'] = uncomma($array[$packageHeight+1]);
        }

        return $data;
    }
}