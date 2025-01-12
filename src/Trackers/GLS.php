<?php

namespace Sauladam\ShipmentTracker\Trackers;

use Sauladam\ShipmentTracker\Event;
use Sauladam\ShipmentTracker\Track;

class GLS extends AbstractTracker
{
    /**
     * @var string
     */
    protected $endpointUrl = 'https://gls-group.eu/app/service/open/rest/DE/{language}/rstt001';

    /**
     * @var array
     */
    protected $trackingUrls = [
        'de' => 'https://gls-group.eu/DE/de/paketverfolgung',
        'en' => 'https://gls-group.eu/DE/en/parcel-tracking'
    ];

    /**
     * @var string
     */
    protected $language = 'de';


    /**
     * Parse the response.
     *
     * @param string $contents
     *
     * @return Track
     * @throws \Exception
     */
    protected function buildResponse($contents)
    {
        $response = $this->jsonToArray($contents);

        if (isset($response['exceptionText'])) {
            $text = $response['exceptionText'];
            throw new \Exception("Unable to retrieve tracking data for [{$this->parcelNumber}]: {$text}");
        }

        return $this->getTrack($response);
    }


    /**
     * Get the shipment status history.
     *
     * @param array $response
     *
     * @return Track
     */
    protected function getTrack(array $response)
    {
        $track = new Track;

        foreach ($response['tuStatus'][0]['history'] as $index => $historyItem) {
            $event = new Event;

            $status = $this->resolveStatus($response, $index);

            $event->setStatus($status);
            $event->setLocation($this->getLocation($historyItem));
            $event->setDescription($historyItem['evtDscr']);
            $event->setDate($this->getDate($historyItem));
            $event->addAdditionalDetails('eventNumber', $response['tuStatus'][0]['progressBar']['evtNos'][$index]);

            $track->addEvent($event);

            if ($status == Track::STATUS_DELIVERED) {
                $track->setRecipient($this->getRecipient($response));
            }

            if ($status == Track::STATUS_PICKUP) {
                $track->addAdditionalDetails('parcelShop', $this->getParcelShopDetails($response));
            }
        }

        return $track->sortEvents();
    }


    /**
     * Get the location.
     *
     * @param array $historyItem
     *
     * @return string
     */
    protected function getLocation(array $historyItem)
    {
        return $historyItem['address']['city'] . ', ' . $historyItem['address']['countryName'];
    }


    /**
     * Get the formatted date.
     *
     * @param array $historyItem
     *
     * @return string
     */
    protected function getDate(array $historyItem)
    {
        return $historyItem['date'] . ' ' . $historyItem['time'];
    }


    /**
     * Get the recipient / person who signed the delivery.
     *
     * @param array $response
     *
     * @return string
     */
    protected function getRecipient(array $response)
    {
        return array_key_exists('signature',$response['tuStatus'][0]) ? $response['tuStatus'][0]['signature']['value'] : null;
    }


    /**
     * Match a shipping status from the given description.
     *
     * @param array $response
     * @param int $historyItemIndex
     *
     * @return string
     *
     */
    protected function resolveStatus(array $response, $historyItemIndex)
    {
        $statuses = [
            Track::STATUS_DELIVERED => [
                '3.120', // unconfirmed
                '3.121',
                '3.0',
            ],
            Track::STATUS_IN_TRANSIT => [
                '0.0',
                '0.100',
                '1.0',
                '11.0',
                '2.0',
                '2.106',
                '2.29',
                '4.40',
                '90.132',
                '35.40',
                '8.0',
                '6.211',
            ],
            Track::STATUS_PICKUP => [
                '2.124',
                '3.124',
            ],
            Track::STATUS_EXCEPTION => [
            ],
        ];

        $eventNumber = $response['tuStatus'][0]['progressBar']['evtNos'][$historyItemIndex];
        $progressStatusInfo = $response['tuStatus'][0]['progressBar']['statusInfo']; // DELIVERED | DELIVEREDPS | INTRANSIT

        foreach ($statuses as $status => $eventNumbers) {
            if (in_array($eventNumber, $eventNumbers)) {
                // if the event status is delivered but the whole shipment status is not delivered yet, override it with IN_TRANSIT
                if (($status === Track::STATUS_DELIVERED) && ($progressStatusInfo !== 'DELIVERED')) {
                    return Track::STATUS_IN_TRANSIT;
                }

                return $status;
            }
        }

        return Track::STATUS_UNKNOWN;
    }


    /**
     * Get the parcel-shop details
     *
     * @param array $response
     *
     * @return array
     */
    protected function getParcelShopDetails(array $response)
    {
        return isset($response['tuStatus'][0]['parcelShop']) && isset($response['tuStatus'][0]['parcelShop']['address'])
            ? $response['tuStatus'][0]['parcelShop']['address']
            : [];
    }


    /**
     * Try to convert the Json string into an array.
     *
     * @param $string
     *
     * @return array
     * @throws \Exception
     */
    protected function jsonToArray($string)
    {
        $array = json_decode($string, true);

        if (!$array) {
            throw new \Exception("Unable to decode GLS Json string [$string] for [{$this->parcelNumber}].");
        }

        return $array;
    }


    /**
     * Build the user friendly url for the given tracking number.
     *
     * @param string $trackingNumber
     * @param null $language
     * @param array $params
     *
     * @return string
     */
    public function trackingUrl($trackingNumber, $language = null, $params = [])
    {
        $language = $language ?: $this->language;

        $url = array_key_exists(
            $language,
            $this->trackingUrls
        ) ? $this->trackingUrls[$language] : $this->trackingUrls['de'];

        $additionalParams = !empty($params) ? $params : $this->trackingUrlParams;

        $qry = http_build_query(array_merge([
            'match' => $trackingNumber,
        ], $additionalParams));

        return $url . '?' . $qry;
    }


    /**
     * Get the endpoint url.
     *
     * @param string $trackingNumber
     * @param null $language
     * @param array $params
     *
     * @return string
     */
    protected function getEndpointUrl($trackingNumber, $language = null, $params = [])
    {
        $language = $language ?: $this->language;

        $url = str_replace('{language}', $language, $this->endpointUrl);

        $additionalParams = !empty($params) ? $params : $this->endpointUrlParams;

        $qry = http_build_query(array_merge([
            'match' => $trackingNumber,
        ], $additionalParams));

        return $url . '?' . $qry;
    }
}
