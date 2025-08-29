<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace Cobweb\SvconnectorFeed\Service;

use Cobweb\Svconnector\Attribute\AsConnectorService;
use Cobweb\Svconnector\Event\ProcessArrayDataEvent;
use Cobweb\Svconnector\Event\ProcessRawDataEvent;
use Cobweb\Svconnector\Event\ProcessResponseEvent;
use Cobweb\Svconnector\Event\ProcessXmlDataEvent;
use Cobweb\Svconnector\Exception\SourceErrorException;
use Cobweb\Svconnector\Service\ConnectorBase;
use Cobweb\Svconnector\Utility\ConnectorUtility;
use Cobweb\Svconnector\Utility\FileUtility;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Service that reads XML feeds for the "svconnector_mobilede" extension.
 */
#[AsConnectorService(type: 'mobilede', name: 'mobile.de connector')]
class ConnectorFeed extends ConnectorBase
{
    protected string $extensionKey = 'svconnector_mobilede';

    /**
     * Verifies that the connection is functional
     * In the case of this service, it is always the case
     * It might fail for a specific file, but it is always available in general
     *
     * @return bool TRUE if the service is available
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Checks the connector configuration and returns notices, warnings or errors, if any.
     *
     * @param array $parameters Connector call parameters
     * @return array
     */
    public function checkConfiguration(array $parameters = []): array
    {
        $result = parent::checkConfiguration(...func_get_args());
        // The "uri" parameter is mandatory
        if (empty($this->parameters['uri'])) {
            $result[ContextualFeedbackSeverity::ERROR->value][] = $this->sL(
                'LLL:EXT:svconnector_mobilede/Resources/Private/Language/locallang.xlf:no_feed_defined'
            );
        }
        return $result;
    }

    /**
     * This method calls the query method and returns the result as is,
     * i.e. the XML from the feed, but without any additional work performed on it
     *
     * @param array $parameters Parameters for the call
     * @return mixed Server response
     * @throws \Exception
     */
    public function fetchRaw(array $parameters = [])
    {
        // Call to parent is used only to raise flag about argument deprecation
        // TODO: remove once method signature is changed in next major version
        parent::fetchRaw(...func_get_args());

        $result = $this->query();
        // Implement post-processing hook
        $hooks = $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][$this->extensionKey]['processRaw'] ?? null;
        if (is_array($hooks) && count($hooks) > 0) {
            trigger_error(
                'Using the processRaw hook is deprecated. Use the ProcessRawDataEvent instead',
                E_USER_DEPRECATED
            );
            foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][$this->extensionKey]['processRaw'] as $className) {
                $processor = GeneralUtility::makeInstance($className);
                $result = $processor->processRaw($result, $this);
            }
        }
        /** @var ProcessRawDataEvent $event */
        $event = $this->eventDispatcher->dispatch(
            new ProcessRawDataEvent($result, $this)
        );
        return $event->getData();
    }

    /**
     * This method calls the query and returns the results from the response as an XML structure
     *
     * @param array $parameters Parameters for the call
     * @return string XML structure
     * @throws \Exception
     */
    public function fetchXML(array $parameters = []): string
    {
        // Call to parent is used only to raise flag about argument deprecation
        // TODO: remove once method signature is changed in next major version
        parent::fetchXML(...func_get_args());

        // Get the feed, which is already in XML
        $xml = $this->query();
        // Implement post-processing hook
        $hooks = $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][$this->extensionKey]['processXML'] ?? null;
        if (is_array($hooks) && count($hooks) > 0) {
            trigger_error(
                'Using the processXML hook is deprecated. Use the ProcessXmlDataEvent instead',
                E_USER_DEPRECATED
            );
            foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][$this->extensionKey]['processXML'] as $className) {
                $processor = GeneralUtility::makeInstance($className);
                $xml = $processor->processXML($xml, $this);
            }
        }
        /** @var ProcessXmlDataEvent $event */
        $event = $this->eventDispatcher->dispatch(
            new ProcessXmlDataEvent($xml, $this)
        );

        return $event->getData();
    }

    /**
     * This method calls the query and returns the results from the response as a PHP array
     *
     * @param array $parameters Parameters for the call
     * @return array PHP array
     * @throws \Exception
     */
    public function fetchArray(array $parameters = []): array
    {
        // Call to parent is used only to raise flag about argument deprecation
        // TODO: remove once method signature is changed in next major version
        parent::fetchArray(...func_get_args());

        // Get the data from the file
        $result = $this->query();
        $result = ConnectorUtility::convertXmlToArray($result);

        $this->logger->info('Structured data', $result);

        // Implement post-processing hook
        $hooks = $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][$this->extensionKey]['processArray'] ?? null;
        if (is_array($hooks) && count($hooks) > 0) {
            trigger_error(
                'Using the processArray hook is deprecated. Use the ProcessArrayDataEvent instead',
                E_USER_DEPRECATED
            );
            foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][$this->extensionKey]['processArray'] as $className) {
                $processor = GeneralUtility::makeInstance($className);
                $result = $processor->processArray($result, $this);
            }
        }
        /** @var ProcessArrayDataEvent $event */
        $event = $this->eventDispatcher->dispatch(
            new ProcessArrayDataEvent($result, $this)
        );
        return $event->getData();
    }

    /**
     * Reads the content of the XML feed defined in the parameter and returns it as an array.
     *
     * NOTE: This method does not implement the "processParameters" hook, as it does not make sense in this case.
     *
     * @param array $parameters Parameters for the call
     * @return mixed Content of the feed
     * @throws \Exception
     */
    protected function query(array $parameters = [])
    {
        // Call to parent is used only to raise flag about argument deprecation
        // TODO: remove once method signature is changed in next major version
        parent::query(...func_get_args());

        $this->logger->info('Call parameters', $this->parameters);
        // Check the configuration
        $problems = $this->checkConfiguration();
        // Log all issues and raise error if any
        $this->logConfigurationCheck($problems);
        if (count($problems[ContextualFeedbackSeverity::ERROR->value]) > 0) {
            $message = '';
            foreach ($problems[ContextualFeedbackSeverity::ERROR->value] as $problem) {
                if ($message !== '') {
                    $message .= "\n";
                }
                $message .= $problem;
            }
            $this->raiseError(
                $message,
                1299257883,
                [],
                SourceErrorException::class
            );
        }

        $headers = $this->parameters['headers'] ?? [];
        if ((array_key_exists('username', $this->parameters)) && (array_key_exists('password', $this->parameters))) {
            $username = $this->parameters['username'];
            $password = $this->parameters['password'];
            $auth = base64_encode("$username:$password");
            if (is_null($headers)) { $headers = []; }
            $headers = array_merge($headers, [ 'Authorization' => 'Basic ' . $auth]);
        }
        if (array_key_exists('accept', $this->parameters)) {
            if (is_null($headers)) { $headers = []; }
            $headers = array_merge($headers, ['Accept' => $this->parameters['accept']]);
        }
        if (array_key_exists('useragent', $this->parameters)) {
            trigger_error(
                '"useragent" property is deprecated. Use headers property instead.',
                E_USER_DEPRECATED
            );
            $headers = array_merge($headers, ['User-Agent' => $this->parameters['useragent']]);
        }

        // fetch all data from all pages
        $data = $this->fetchAllPages($this->parameters['uri'], $headers);

        if(isset($parameters['get-detail']) && $this->parameters['get-detail'] === true) {
            $data = $this->fetchAdDetails($data, $headers);
        }

        if (isset($parameters['equipment-fields']) && is_string($this->parameters['equipment-fields'])) {
            $data = $this->transformFieldsToEquipments($data, $this->parameters['equipment-fields']);
        }

        if ($data === false) {
            $message = sprintf(
                    $this->sL('LLL:EXT:svconnector_mobilede/Resources/Private/Language/locallang.xlf:feed_not_fetched'),
                    $this->parameters['uri'],
                    $fileUtility->getError()
            );
            $this->raiseError(
                $message,
                1299257894,
                [],
                SourceErrorException::class
            );
        }
        // Check if the current charset is the same as the file encoding
        // Don't do the check if no encoding was defined
        // TODO: add automatic encoding detection by reading the encoding attribute in the XML header
        if (empty($this->parameters['encoding'])) {
            $encoding = '';
            $isSameCharset = true;
        } else {
            // Standardize charset name and compare
            $encoding = $this->parameters['encoding'];
            $isSameCharset = $this->getCharset() === $encoding;
        }
        // If the charset is not the same, convert data
        if (!$isSameCharset) {
            $data = $this->getCharsetConverter()->conv($data, $encoding, $this->getCharset());
        }

        // Process the result if any hook is registered
        $hooks = $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][$this->extensionKey]['processResponse'] ?? null;
        if (is_array($hooks) && count($hooks) > 0) {
            trigger_error(
                'Using the processResponse hook is deprecated. Use the ProcessResponseEvent instead',
                E_USER_DEPRECATED
            );
            foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][$this->extensionKey]['processResponse'] as $className) {
                $processor = GeneralUtility::makeInstance($className);
                $data = $processor->processResponse($data, $this);
            }
        }
        /** @var ProcessResponseEvent $event */
        $event = $this->eventDispatcher->dispatch(
            new ProcessResponseEvent($data, $this)
        );

        // Return the result
        return $event->getResponse();
    }

    /**
     * Fetches all data from mobile.de from all pages.
     *
     * @param string $uri Address of the file to read
     * @param array $headers Headers to pass on to the request
     * @return string XML string with all ad(s) from all pages
     */
    private function fetchAllPages(string $uri, array $headers): string
    {
        $fileUtility = GeneralUtility::makeInstance(FileUtility::class);
        $allAds = '';
        $total = 0;
        $currentPage = 1;
        $maxPages = 1;

        do {
            // Update the URI with the current page number
            $separator = (strpos($uri, '?') === false) ? '?' : '&';
            $pageUri = $uri . $separator . 'page.number=' . $currentPage;

            $pageData = $fileUtility->getFileContent($pageUri, $headers);

            if ($pageData === false) {
                throw new SourceErrorException('Error fetching data from URI: ' . $pageUri);
            }

            // Parse the XML data
            $xml = simplexml_load_string($pageData);
            if ($xml === false) {
                throw new SourceErrorException('Error parsing XML data from URI: ' . $pageUri);
            }

            // Extract total from the first page
            if ($currentPage === 1) {
                $total = (int)$xml->total;
            }

            // Append all <ad> elements to $allAds
            foreach ($xml->ads->ad as $ad) {
                $allAds .= $ad->asXML();
            }

            // Get pagination information
            $currentPage = (int)$xml->currentPage;
            $maxPages = (int)$xml->maxPages;

            $currentPage++;
        } while ($currentPage <= $maxPages);

        // Construct the XML
        $allPagesDataXml = '<searchResult>';
        $allPagesDataXml .= '<total>' . $total . '</total>';
        $allPagesDataXml .= '<ads>' . $allAds . '</ads>';
        $allPagesDataXml .= '</searchResult>';

        return $allPagesDataXml;
    }
    
    /**
     * Fetches detailed ad data from mobile.de and updates the XML document.
     *
     * @param string $data The XML data.
     * @param array $headers The headers for the HTTP request.
     * @return string Updated XML data with ad details.
     */
    protected function fetchAdDetails(string $data, array $headers): string
    {
        $fileUtility = GeneralUtility::makeInstance(FileUtility::class);
        $doc = new \DOMDocument();
        $doc->loadXML($data);
        $xpath = new \DOMXPath($doc);

        $ads = $xpath->query("//ads/ad");
        foreach ($ads as $index => $ad) {
            $mobileAdId = $ad->getElementsByTagName('mobileAdId')->item(0)->nodeValue;
            $adUrl = "https://services.mobile.de/search-api/ad/{$mobileAdId}";

            $adData = $fileUtility->getFileContent($adUrl, $headers);
            $adDoc = new \DOMDocument();
            $adDoc->loadXML($adData);

            // Overwrite <ad> with new data from ad detail
            $newAd = $doc->importNode($adDoc->documentElement, true);
            $ad->parentNode->replaceChild($newAd, $ad);
        }

        return $doc->saveXML();
    }

    /**
     * Transforms specified fields to equipment format in the XML data.
     *
     * @param string $data The XML data.
     * @param string $fields The fields to transform as a comma-separated string.
     * @return string Updated XML data with transformed fields.
     */
    protected function transformFieldsToEquipments(string $data, string $fields): string
    {
        $fieldsArray = explode(',', $fields);

        $doc = new \DOMDocument();
        $doc->loadXML($data);
        $xpath = new \DOMXPath($doc);

        foreach ($xpath->query("//ads/ad") as $ad) {
            $equipmentsElement = $doc->createElement('equipments');
            $externalIds = [];

            foreach ($fieldsArray as $field) {
                $nodes = $xpath->query("{$field}", $ad);
                foreach ($nodes as $node) {
                    $values = [];
                    if ($node->getElementsByTagName('value')->length > 0) {
                        foreach ($node->getElementsByTagName('value') as $valueNode) {
                            $values[] = $valueNode->nodeValue;
                        }
                    } else {
                        $values[] = $node->nodeValue;
                    }

                    foreach ($values as $value) {
                        $equipmentElement = $doc->createElement('equipment');
                        $codeElement = $doc->createElement('code', $field);
                        $valueElement = $doc->createElement('value', $value);
                        $externalIdValue = ($value === 'true') ? $field : "{$field}.{$value}";
                        $externalIdElement = $doc->createElement('external_id', $externalIdValue);

                        $equipmentElement->appendChild($codeElement);
                        $equipmentElement->appendChild($valueElement);
                        $equipmentElement->appendChild($externalIdElement);
                        $equipmentsElement->appendChild($equipmentElement);

                        // Add external_id to the collection
                        $externalIds[] = $externalIdValue;
                    }

                    // Remove the old node
                    $node->parentNode->removeChild($node);
                }
            }

            // Create equipmentCollection element
            $equipmentCollectionElement = $doc->createElement('equipmentCollection', implode(',', $externalIds));

            // Append equipmentCollection element after equipments element
            $ad->appendChild($equipmentsElement);
            $ad->appendChild($equipmentCollectionElement);
        }

        return $doc->saveXML();
    }

}
