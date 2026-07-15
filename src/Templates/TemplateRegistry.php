<?php

declare(strict_types=1);

namespace Uzbek\Sms\Templates;

use Uzbek\Sms\Exceptions\SmsException;

/**
 * Named message templates from sms.templates.list. With sms.templates.enforce
 * on, only text matching one of them may leave through the builders.
 */
final class TemplateRegistry
{
    /**
     * @param  array<string, string|int|float>  $params
     */
    public function render(string $name, array $params = []): string
    {
        $template = config("sms.templates.list.{$name}");

        if (! is_string($template)) {
            throw new SmsException("SMS template [{$name}] is not defined in sms.templates.list.");
        }

        foreach ($params as $key => $value) {
            $template = str_replace(':'.$key, (string) $value, $template);
        }

        return $template;
    }

    public function matches(string $text): bool
    {
        foreach ((array) config('sms.templates.list', []) as $template) {
            if (is_string($template) && preg_match($this->pattern($template), $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * :placeholder segments become wildcards, everything else matches literally.
     */
    private function pattern(string $template): string
    {
        $parts = (array) preg_split('/(:[a-zA-Z_][a-zA-Z0-9_]*)/', $template, -1, PREG_SPLIT_DELIM_CAPTURE);

        $regex = '';

        foreach ($parts as $part) {
            $part = (string) $part;

            $regex .= str_starts_with($part, ':') && strlen($part) > 1
                ? '.+?'
                : preg_quote($part, '/');
        }

        return '/^'.$regex.'$/su';
    }
}
