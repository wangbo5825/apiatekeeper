<?php

declare(strict_types=1);

namespace Apigate;

/**
 * 响应加工（filter 阶段）：替换 JSON 属性、保存属性到变量、JSON↔XML 转换。
 * 失败时透传原始响应。
 */
final class ResponseTransformer
{
    /**
     * @param array<string,mixed> $params
     * @return array{0:string,1:string} [body, contentType]
     */
    public static function apply(array $params, string $body, string $contentType, Context $ctx): array
    {
        $op = (string) ($params['op'] ?? '');
        return match ($op) {
            'replace' => self::replace($params, $body, $contentType, $ctx),
            'save_variable' => self::saveVariable($params, $body, $ctx),
            'convert' => self::convert($params, $body, $contentType),
            default => [$body, $contentType],
        };
    }

    /** @return array{0:string,1:string} */
    private static function replace(array $params, string $body, string $contentType, Context $ctx): array
    {
        $path = (string) ($params['path'] ?? '');
        if ($path === '') {
            return [$body, $contentType];
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return [$body, $contentType];
        }
        $value = RuleEngine::resolveValue((string) ($params['value'] ?? ''), $ctx) ?? '';
        self::setPath($data, $path, $value);
        $out = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return [$out === false ? $body : $out, $contentType];
    }

    /** @return array{0:string,1:string} */
    private static function saveVariable(array $params, string $body, Context $ctx): array
    {
        $path = (string) ($params['path'] ?? '');
        $target = (string) ($params['target'] ?? '');
        if ($path === '' || $target === '') {
            return [$body, 'application/json'];
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return [$body, 'application/json'];
        }
        $value = self::pathValue($data, $path);
        if (is_scalar($value)) {
            $ctx->setVariable($target, (string) $value);
        }
        return [$body, 'application/json'];
    }

    /** @return array{0:string,1:string} */
    private static function convert(array $params, string $body, string $contentType): array
    {
        $format = (string) ($params['format'] ?? '');
        if ($format === 'json_to_xml') {
            $data = json_decode($body, true);
            if (!is_array($data)) {
                return [$body, $contentType];
            }
            $root = (string) ($params['root'] ?? 'response');
            $xml = self::arrayToXml($data, $root);
            return [$xml, (string) ($params['content_type'] ?? 'application/xml')];
        }
        if ($format === 'xml_to_json') {
            $prev = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($body);
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            if ($xml === false) {
                return [$body, $contentType];
            }
            $json = json_encode($xml, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return [$json === false ? $body : $json, (string) ($params['content_type'] ?? 'application/json')];
        }
        return [$body, $contentType];
    }

    /** @param array<string,mixed> $data */
    private static function arrayToXml(array $data, string $root): string
    {
        $doc = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><' . $root . '/>');
        self::fillXml($doc, $data);
        $out = $doc->asXML();
        return $out === false ? '' : $out;
    }

    /** @param array<string,mixed> $data */
    private static function fillXml(\SimpleXMLElement $node, array $data): void
    {
        foreach ($data as $k => $v) {
            $tag = is_int($k) ? 'item' : (string) $k;
            if (is_array($v)) {
                $child = $node->addChild($tag);
                self::fillXml($child, $v);
            } else {
                $node->addChild($tag, htmlspecialchars((string) $v, ENT_XML1));
            }
        }
    }

    /** @param array<string,mixed> $data */
    private static function pathValue(array $data, string $path): mixed
    {
        foreach (explode('.', $path) as $seg) {
            if (is_array($data) && array_key_exists($seg, $data)) {
                $data = $data[$seg];
            } else {
                return null;
            }
        }
        return $data;
    }

    /** @param array<string,mixed> $data */
    private static function setPath(array &$data, string $path, mixed $value): void
    {
        $segs = explode('.', $path);
        $cur = &$data;
        $last = array_pop($segs);
        foreach ($segs as $seg) {
            if (!isset($cur[$seg]) || !is_array($cur[$seg])) {
                $cur[$seg] = [];
            }
            $cur = &$cur[$seg];
        }
        $cur[$last] = $value;
    }
}
