<?php
/**
 * Copyright © Thomas Klein, All rights reserved.
 * See LICENSE bundled with this library for license details.
 */
declare(strict_types=1);

namespace Zoho\Desk\Client;

use cardinalby\ContentDisposition\ContentDisposition;
use Zoho\Desk\Exception\InvalidRequestException;
use function curl_errno;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function is_array;
use function json_decode;
use function mb_substr;
use function rtrim;
use const CURLINFO_HEADER_SIZE;
use const CURLINFO_HTTP_CODE;

final class Request implements RequestInterface
{
    /**
     * @var resource
     */
    private $curlResource;

    public function __construct($curlResource)
    {
        $this->curlResource = $curlResource;
    }

    public function execute(): ResponseInterface
    {
        $response = curl_exec($this->curlResource);

        if ($response === false) {
            throw InvalidRequestException::createRequestErrorException(
                curl_error($this->curlResource),
                curl_errno($this->curlResource)
            );
        }

        $responseInfo = curl_getinfo($this->curlResource);
        $responseCode = curl_getinfo($this->curlResource, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($this->curlResource, CURLINFO_HEADER_SIZE);
        $contentType = curl_getinfo($this->curlResource, CURLINFO_CONTENT_TYPE);

        $body = [];
        if (strpos($contentType, 'application/json') !== false) {
            $body = json_decode(mb_substr($response, $headerSize), true) ?: [];
        } else {

            $headers = $this->getHeaders(substr($response, 0, $headerSize));

            $contentDispositionParsed = ContentDisposition::parse($headers['content-disposition']);
            $fileName = 'document-'.uniqid();
            if(array_key_exists('filename*', $contentDispositionParsed->getParameters()) && !empty($contentDispositionParsed->getFilename())){
                $fileName = $contentDispositionParsed->getFilename();
            }
            $body['content'] = mb_substr($response, $headerSize);
            $body['filename'] = $fileName;

        }
        curl_close($this->curlResource);

        if (!$responseInfo || $responseCode >= 400) {
            throw new InvalidRequestException($this->buildErrorMessage($body));
        }

        return new Response($body, $responseInfo);
    }

    private function getHeaders($headerText)
    {
        foreach (explode("\r\n", $headerText) as $i => $line) {
            if ($i === 0) {
                $headers['http_code'] = $line;
            } elseif (!empty(trim($line))) {
                list ($key, $value) = explode(': ', $line);

                $headers[$key] = $value;
            }
        }

        return $headers;
    }

    private function buildErrorMessage(array $body): string
    {
        $message = 'An error occurred on the API gateway.';

        if (isset($body['message'])) {
            $message = $body['message'];

            if (isset($body['errors']) && is_array($body['errors'])) {
                $message .= ': ';
                foreach ($body['errors'] as $error) {
                    $message .= $error['fieldName'] . ' is ' . $error['errorType'] . ', ';
                }
                $message = rtrim($message, ', ') . '.';
            }
        }

        return $message;
    }
}
