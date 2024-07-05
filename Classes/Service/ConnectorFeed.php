<?php
namespace Cobweb\SvconnectorMobilede\Service;

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

use Cobweb\Svconnector\Exception\SourceErrorException;
use Cobweb\Svconnector\Service\ConnectorBase;
use Cobweb\Svconnector\Utility\ConnectorUtility;
use Cobweb\Svconnector\Utility\FileUtility;
use TYPO3\CMS\Core\Messaging\AbstractMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Service that reads XML feeds for the "svconnector_mobilede" extension.
 */
class ConnectorFeed extends ConnectorBase
{
    protected string $extensionKey = 'svconnector_mobilede';

    protected string $type = 'mobilede';

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Returns the class as a string. Seems to be needed by phpunit when an exception occurs during a test run.
     *
     * @return string
     */
    public function __toString()
    {
        return 'ConnectorFeed';
    }

    public function getName(): string
    {
        return 'XML/RSS feed connector';
    }

    /**
     * Verifies that the connection is functional
     * In the case of this service, it is always the case
     * It might fail for a specific file, but it is always available in general
     *
     * @return boolean TRUE if the service is available
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
        $result = parent::checkConfiguration($parameters);
        // The "uri" parameter is mandatory
        if (empty($parameters['uri'])) {
            $result[AbstractMessage::ERROR][] = $this->sL('LLL:EXT:svconnector_mobilede/Resources/Private/Language/locallang.xlf:no_feed_defined');
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
        $result = $this->query($parameters);
        // Implement post-processing hook
        $hooks = $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][$this->extensionKey]['processRaw'] ?? null;
        if (is_array($hooks)) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][$this->extensionKey]['processRaw'] as $className) {
                $processor = GeneralUtility::makeInstance($className);
                $result = $processor->processRaw($result, $this);
            }
        }

        return $result;
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
        // Get the feed, which is already in XML
        $xml = $this->query($parameters);
        // Implement post-processing hook
        $hooks = $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][$this->extensionKey]['processXML'] ?? null;
        if (is_array($hooks)) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][$this->extensionKey]['processXML'] as $className) {
                $processor = GeneralUtility::makeInstance($className);
                $xml = $processor->processXML($xml, $this);
            }
        }

        return $xml;
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
        // Get the data from the file
        $result = $this->query($parameters);
        $result = ConnectorUtility::convertXmlToArray($result);

        $this->logger->info('Structured data', $result);

        // Implement post-processing hook
        $hooks = $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][$this->extensionKey]['processArray'] ?? null;
        if (is_array($hooks)) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][$this->extensionKey]['processArray'] as $className) {
                $processor = GeneralUtility::makeInstance($className);
                $result = $processor->processArray($result, $this);
            }
        }
        return $result;
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
        $this->logger->info('Call parameters', $parameters);
        // Check the configuration
        $problems = $this->checkConfiguration($parameters);
        // Log all issues and raise error if any
        $this->logConfigurationCheck($problems);
        if (count($problems[AbstractMessage::ERROR]) > 0) {
            $message = '';
            foreach ($problems[AbstractMessage::ERROR] as $problem) {
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

        $headers = null;
        if ((array_key_exists('username', $parameters)) && (array_key_exists('password', $parameters))) {
            $username = $parameters['username'];
            $password = $parameters['password'];
            $auth = base64_encode("$username:$password");
            if (is_null($headers)) { $headers = []; }
            $headers = array_merge($headers, [ 'Authorization' => 'Basic ' . $auth]);
        }
        if (array_key_exists('accept', $parameters)) {
            if (is_null($headers)) { $headers = []; }
            $headers = array_merge($headers, ['Accept' => $parameters['accept']]);
        }
        if (array_key_exists('useragent', $parameters)) {
            if (is_null($headers)) { $headers = []; }
            $headers = array_merge($headers, ['User-Agent' => $parameters['useragent']]);
        }

        $fileUtility = GeneralUtility::makeInstance(FileUtility::class);
        $data = $fileUtility->getFileContent($parameters['uri'], $headers);

        if(isset($parameters['get-detail']) && $parameters['get-detail'] === true) {
            $data = $this->fetchAdDetails($data, $headers);
        }

        if (isset($parameters['equipment-fields']) && is_string($parameters['equipment-fields'])) {
            $data = $this->transformFieldsToEquipments($data, $parameters['equipment-fields']);
        }

        if ($data === false) {
            $message = sprintf(
                    $this->sL('LLL:EXT:svconnector_mobilede/Resources/Private/Language/locallang.xlf:feed_not_fetched'),
                    $parameters['uri'],
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
        if (empty($parameters['encoding'])) {
            $encoding = '';
            $isSameCharset = true;
        } else {
            // Standardize charset name and compare
            $encoding = $parameters['encoding'];
            $isSameCharset = $this->getCharset() === $encoding;
        }
        // If the charset is not the same, convert data
        if (!$isSameCharset) {
            $data = $this->getCharsetConverter()->conv($data, $encoding, $this->getCharset());
        }

        // Process the result if any hook is registered
        $hooks = $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][$this->extensionKey]['processResponse'] ?? null;
        if (is_array($hooks)) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS'][$this->extensionKey]['processResponse'] as $className) {
                $processor = GeneralUtility::makeInstance($className);
                $data = $processor->processResponse($data, $this);
            }
        }

        // Return the result
        return $data;
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

            foreach ($fieldsArray as $field) {
                $nodes = $xpath->query("{$field}", $ad);
                foreach ($nodes as $node) {
                    $equipmentElement = $doc->createElement('equipment');
                    $codeElement = $doc->createElement('code', $field);

                    if ($node->getElementsByTagName('value')->length > 0) {
                        $values = [];
                        foreach ($node->getElementsByTagName('value') as $valueNode) {
                            $values[] = $valueNode->nodeValue;
                        }
                        $valueElement = $doc->createElement('value', implode(',', $values));
                    } else {
                        $valueElement = $doc->createElement('value', $node->nodeValue);
                    }

                    $equipmentElement->appendChild($codeElement);
                    $equipmentElement->appendChild($valueElement);
                    $equipmentsElement->appendChild($equipmentElement);

                    // Remove the old node
                    $node->parentNode->removeChild($node);
                }
            }

            $ad->appendChild($equipmentsElement);
        }

        return $doc->saveXML();
    }

}
