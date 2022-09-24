<?php

$ini_array = parse_ini_file("config.ini");
define("CDO_DATASET", "GSOM");
define("DATE_FORMAT", "Y-m-d"); 
define("CDO_REQUEST_YEAR_LIMIT_STEP", 10);

// rest route 
function get_data(WP_REST_Request $data) {
    $zip = $data['zip'];
    $distance = $data['distance'];
    $duration = $data['duration'];

    $coords = getCoordinates($zip);
    $bboxSwNe = getBoundingBoxSwNe($coords, $distance);
    $startDateStr = getStartDateStr($duration);
    $endDateStr = getEndDateStr();
    // we need to know the stations to query in, in order to query data. 
    // find what stations have data in the date range and in the bounding box
    $stationsResponseObj = getCdoStations(CDO_DATASET, $bboxSwNe, $startDateStr);
    $stationsStr = getCdoStationsStr($stationsResponseObj);
    $resultsets = getCdoDataResultsets(CDO_DATASET, $startDateStr, $endDateStr, $stationsStr, "TAVG");
    $byStation = transformByStation($resultsets);
    $trimmed = trimArrays($byStation);

    $responseObj = [
        'coords' => $coords,
        'bbox' => $bboxSwNe,
        'stations' => $stationsResponseObj,
        'stationsStr' => $stationsStr,
        'data' => $trimmed
    ];

    return json_encode($responseObj);
}

//
// CDO Web Services API v2
//

/*
    Here's how to understand the data format. 

    The user decides how many years to query for and it may be a large number of years like 80.
    CDO v2 API has a limit on the date range for a query (max of 10 year range for querying monthly data).
    So we break up the query we wish we could send into chunks of 10 years or less and query for each date range chunk.
    Within one query, there may be multiple pages, because sometimes there are more results than fit in max length of a response.

    "resultsets" means an array of resultsets.
    One "resultset" is a set of pages for a specific date range. 

    We organize this data into an array of years per weather station, before sending it to the client, 
    in the transform functions.
*/

function getCdoStations($datasetId, $bbox, $startDate) {
    try {
        $baseUrl = "https://www.ncdc.noaa.gov/cdo-web/api/v2/stations";
        $finalUrl = $baseUrl . (empty($datasetId) ? "?x=y": "?datasetid=$datasetId") . (empty($bbox) ? "": "&extent=$bbox") . (empty($startDate) ? "": "&startdate=$startDate") . "&datatypeid=TAVG";

        $headers = array(
            "token: " . $GLOBALS['ini_array']['cdo_v2_api_key']
        );
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $finalUrl);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        $jsonString = curl_exec($curl);
        curl_close($curl);

        if (responseGood($jsonString)) {
            return json_decode($jsonString);
        } else {
            return "response not good. " . $jsonString;
        }
    } catch (Exception $e) {
        return $e->getMessage();
    }
}
function getCdoStationsStr($responseObj) {
    if (is_null($responseObj)) {
        return NULL;
    }
    if (property_exists($responseObj, "results")) {
        $length = count($responseObj->results);
    } else {
        $length = 0;
    }
    $listStr = "";
    
    for ($i = 0; $i < $length; $i++) {
        $listStr .= "&stationid=" . $responseObj->results[$i]->id;
    }
    toLog('cdo stations string ' . $listStr);
    return $listStr;
}
function getCdoData($datasetId, $startDate, $endDate, $stations, $dataTypes, $offset) {
    // for use by getCdoResultsetPages
    $baseUrl = "https://www.ncdc.noaa.gov/cdo-web/api/v2/data";
    $finalUrl = $baseUrl . "?datasetid=$datasetId" . "&limit=1000" . "&startdate=$startDate" . "&enddate=$endDate" . (empty($stations) ? "" : $stations) . (empty($dataTypes) ? "" : "&datatypeid=$dataTypes") . (empty($offset) ? "" : "&offset=$offset");
    toLog('final cdo data url ' . $finalUrl);

    $headers = array(
        "token: " . $GLOBALS['ini_array']['cdo_v2_api_key']
    );
    try {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $finalUrl);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        $jsonString = curl_exec($curl);
        curl_close($curl);
    } catch (Exception $e) {
        toLog('error in getCdoData');
        toLog($e->getMessage());
        return NULL;
    }
    if (responseGood($jsonString)) {
        return $jsonString; 
    } else {
        return NULL;
    }
}
function getCdoResultsetPages($datasetId, $startDateStr, $endDateStr, $stations, $dataTypes) {
    // for use by getCdoDataResultsets
    $responseArr = [];
    $done = false;
    $offset = 1;
    while (!$done) {
        $needSleep = false;
        do {
            $responseJson = getCdoData($datasetId, $startDateStr, $endDateStr, $stations, $dataTypes, $offset);
            $response = json_decode($responseJson);
            $needSleep = $response->status == '429';
            if ($needSleep) {
                usleep(500); 
            } 
        } while ($needSleep);
        
        array_push($responseArr, $response);
        $offset = getNextOffset($response->metadata->resultset);
        if ($offset == -1) {
            $done = true;
        }
    }
    
    return $responseArr;
}
function getNextOffset($resultsetMeta) {
    try {
        // for use by getCdoResultsetPages
        if ($resultsetMeta->offset + $resultsetMeta->limit <= $resultsetMeta->count) {
            return $resultsetMeta->offset + $resultsetMeta->limit;
        } else {
            return -1;
        }
    } catch(Exception $e) {
        return -1;
    }
}
function getCdoDataResultsets($datasetId, $startDateStr, $endDateStr, $stations, $dataTypes) {
    // this is the function you want to call. 
    $delimeter = "___";
    // this function gets passed the "master" start and end date: the actual range expected by the user.
    // getDateRangeArray breaks that range up into an array of ranges, because CDO API has a limit on date range length.
    $dateRangesArray = getDateRangeArray($startDateStr, $endDateStr);

    // "resultsets" being a set of resultsets to queries sent to CDO v2 Data endpoint. 1 resultset if less than 10 years, etc. 
    // the api disallows date range of more than 10 years. so we paginate through date ranges to produce an array of resultsets. 
    // within a resultset, result count may exceed API max result limit of 1000 results. so we paginate within a resultset to get all results.
    // $resultsets is the raw data from API queries, in a 2-dimensional array: 10 year date ranges and pages of results for each of those ranges.
    $resultsets = [];

    foreach($dateRangesArray as $dateRange) {
        $key = $dateRange[0] . $delimeter . $dateRange[1];
        $resultsets[$key] = getCdoResultsetPages($datasetId, $dateRange[0], $dateRange[1], $stations, $dataTypes);
    }
    return $resultsets;
}

//
// coordinate stuff
//

function getGeocoding($zip) {
    // mapquest geocoding api
    $mapquestConsumerKey = $GLOBALS['ini_array']['mapquest_key'];
    $baseUrl = 'http://open.mapquestapi.com/geocoding/v1/address';
    $finalUrl = $baseUrl . '?key=' . $mapquestConsumerKey . '&location={"zip":"' . $zip . '","country":"US"}';

    try {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $finalUrl);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        $jsonString = curl_exec($curl);
        curl_close($curl);
        $obj = json_decode($jsonString);
        return $obj;
    } catch (Exception $e) {
        toLog('error in getGeocoding');
        toLog($e->getMessage());
        return NULL;
    }
}
function getCoordinates($zip) {
    $data = getGeocoding($zip);
    if (is_null($data)) {
        return NULL;
    }
    try {
        return [$data->results[0]->locations[0]->latLng->lat, $data->results[0]->locations[0]->latLng->lng];
    } catch (Exception $e) {
        toLog('error in getCoordinates');
        toLog($e->getMessage());
        return NULL;
    }
}
function getBoundingBoxSwNe($coords, $distance) {
    // cdo v2 uses this google map format
    // southwest and northeast
    try {
        $bottomLeft = getOffsetCoordinate((float) $coords[0], (float) $coords[1], $distance, 3.92699);
        $topRight = getOffsetCoordinate((float) $coords[0], (float) $coords[1], $distance, 0.785398);
    } catch (Exception $e) {
        toLog('error in getBoundingBox');
        toLog($e->getMessage());
        return NULL;
    }
    // toLog('bounding box southwest: ' . $bottomLeft[0] . ',' . $bottomLeft[1]);
    // toLog('bounding box northeast: ' . $topRight[0] . ',' . $topRight[1]);
    
    $string = $bottomLeft[0] . ',' . $bottomLeft[1] . ',' . $topRight[0] . ',' . $topRight[1];
    // toLog('bounding box: ' . $string);
    return $string;
}
function getOffsetCoordinate($lat, $lon, $d, $bearing) {
    // based on https://stackoverflow.com/questions/7222382/get-lat-long-given-current-point-distance-and-bearing
    // takes lat float, long float, distance in km, and bearing in rads
    $r = 6378.1; // radius of earth in km
    
    $lat = deg2rad($lat);
    $lon = deg2rad($lon);
    $lat2 = asin( (sin($lat) * cos($d / $r) ) + (cos($lat) * sin($d / $r) * cos($bearing) ) );
    $lon2 = $lon + atan2(sin($bearing) * sin($d / $r) * cos($lat), cos($d / $r) - (sin($lat) * sin($lat2) ) );
    $lat2 = rad2deg($lat2);
    $lon2 = rad2deg($lon2);

    return [$lat2, $lon2];
}

//
// date stuff
//

function getStartDateStr($duration) {
    $date = new DateTime();
    $date->modify('-' . $duration . ' years');
    $str = $date->format(DATE_FORMAT);
    
    return $str;
}
function getEndDateStr() {
    $date = new DateTime();
    $str = $date->format(DATE_FORMAT);
    
    return $str;
}
function getDateRangeArray($startDateStr, $endDateStr) {
    // returns an array of arrays, each being a date range in the form of an array of 2 DateTime strings: [startDateStr, endDateStr].
    // this is useful because the CDO API doesn't allow date ranges longer than 10 years.
    
    $startDate = new DateTime($startDateStr);
    $endDate = new DateTime($endDateStr);
    
    $interval = date_diff($startDate, $endDate);
    
    $diffYearsStr = $interval->format('%Y');
    toLog("diff years: " . $diffYearsStr);
    $diffYears = intval($diffYearsStr);
    if ($diffYears <= CDO_REQUEST_YEAR_LIMIT_STEP) {
        return [[$startDateStr, $endDateStr]];
    } else {
        $rangeArray = [];

        $pageEnd = new DateTime($startDateStr);
        $done = false;
        while(!$done) {
            $pageStart = new DateTime($pageEnd->format(DATE_FORMAT));
            $pageEnd->modify('+' . CDO_REQUEST_YEAR_LIMIT_STEP . ' years');
            if (!comesBefore($pageEnd, $endDate)) {
                $pageEnd = $endDate;
                $done = true;
            }
            array_push($rangeArray, [$pageStart->format(DATE_FORMAT), $pageEnd->format(DATE_FORMAT)]);
        }
        return $rangeArray;
    }
} 
function comesBefore($aDate, $referenceDate) {
    $interval = $aDate->diff($referenceDate);
    toLog('comesBefore - aDate: ' . $aDate->format(DATE_FORMAT) . ' referenceDate: ' . $referenceDate->format(DATE_FORMAT) . ' aDate->diff(referenceDate)->invert: ' . $interval->invert . ' ->days: ' . $interval->days);
    if ($interval->invert == 1) {
        // invert is 1 when aDate comes after referenceDate
        return false;
    } 
    if ($interval->days == 0) {
        // if the 2 dates are the same
        return false;
    }
    return true;
}


//
// validation stuff
//

function isJson($string) {
    json_decode($string);
    return json_last_error() === JSON_ERROR_NONE;
}
function responseGood($response) {
    if (!is_string($response)) {
        return false;
    }
    if (!isJson($response)) {
        return false;
    }
    if (strlen($response) < 3) {
        return false;
    }
    return true;
}

//
// data transform stuff (for data from CDO API v2)
//

function transformByStation($resultsets) {
    $transformedData = [];
    
    foreach($resultsets as $value) {
        foreach($value as $page) {
            foreach($page->results as $result) {
                if (!array_key_exists($result->station, $transformedData)) {
                    $transformedData[$result->station] = [$result];
                } else {
                    array_push($transformedData[$result->station], $result);
                }
            }
        }
    }


    return $transformedData;
}
function compare($a, $b) {
    $dateA = new DateTime($a->date);
    $dateB = new DateTime($b->date);
    $interval = date_diff($dateA, $dateB);
    if ($interval->days == 0) {
        return 0;
    }
    if ($interval->invert) {
        return 1;
    } else {
        return -1;
    }
}
function transformByYear($byStationStation) {
    $transformedData = [];

    foreach($byStationStation as $d) {
        $date = new DateTime($d->date);
        $yearStr = $date->format('Y');
        if (!array_key_exists($yearStr, $transformedData)) {
            $transformedData[$yearStr] = [$d];
        } else {
            array_push($transformedData[$yearStr], $d);
        }
    }

    return $transformedData;
}
function trimArrays($byStation) {
    foreach($byStation as $stationId => $data) {
        $byStation[$stationId] = trimToConsecutive($data);
    }
    return $byStation;
}
function trimToConsecutive($arr) {
    usort($arr, "compare");

    $longestContinuousLength = 0; // longest series of data items with no gap
    $offsetForLongest = 0; // used to get subset of array, usign array_slice
    $countSinceGap = 0;
    $lastDate = NULL;
    $len = count($arr);
    $startInd = 0;
    for ($i = 0; $i < $len; $i++) {
        if (is_null($lastDate)) {
            // for first item
            $countSinceGap++;
        } else {
            $interval = date_diff(new DateTime($arr[$i]->date), new DateTime($lastDate));
            if ($interval->m > 1) {
                // gap found
                if ($countSinceGap > $longestContinuousLength) {
                    $longestContinuousLength = $countSinceGap;
                    $offsetForLongest = $startInd;
                }
                $countSinceGap = 0;
                $startInd = $i;
                $lastDate = NULL;
            } elseif ($interval->m == 1) {
                $countSinceGap++;
            } else {
                // 
                toLog('unexpectedly encountered interval with 0 months, in trimToConsecutive');
            }
        }
        $lastDate = $arr[$i]->date;
    }
    if ($countSinceGap > $longestContinuousLength) {
        $longestContinuousLength = $countSinceGap;
        $offsetForLongest = $startInd;
    }

    return array_slice($arr, $offsetForLongest, $longestContinuousLength);
}


function toLog($arg) {
    $str = '';
    if (is_string($arg)) {
        $str = $arg;
    }
    if (is_object($arg) || is_array($arg)) {
        $str = print_r($arg, true);
    }
    // print_r($str);
    // echo "\n";
}