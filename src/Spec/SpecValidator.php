<?php

declare(strict_types=1);

namespace Docuconf\Spec;

use Docuconf\Duration;
use Docuconf\Re2;
use Docuconf\VarParser;

/**
 * Checks a declaration before it is used (SPEC §11.2 item 2): names,
 * descriptions, defaults against their own constraints, RE2-only patterns,
 * and the file-input rules the meta-schema enforces.
 *
 * @internal
 */
final class SpecValidator
{
    public const ENV_NAME = '/^[A-Z][A-Z0-9_]*$/D';
    public const INPUT_NAME = '/^[a-z]([-a-z0-9]{0,40}[a-z0-9])?$/D';
    private const SERVICE_NAME = '/^[a-z0-9]([-a-z0-9]{0,61}[a-z0-9])?$/D';
    private const RESERVED_DIRS = [
        '/', '/app', '/bin', '/boot', '/dev', '/etc', '/etc/pki', '/etc/ssl',
        '/etc/ssl/certs', '/home', '/lib', '/lib64', '/opt', '/proc', '/root',
        '/run', '/sbin', '/srv', '/sys', '/tmp', '/usr', '/usr/lib', '/usr/local',
        '/usr/share', '/var', '/var/lib', '/var/run',
    ];

    /** @return list<string> */
    public static function problems(ContractSpec $c): array
    {
        $p = [];
        if (!preg_match(self::SERVICE_NAME, $c->name)) {
            $p[] = "service name \"{$c->name}\" must be a DNS label: lowercase letters, digits and '-', at most 63 characters";
        }
        foreach ($c->sortedVars() as $name => $v) {
            foreach (self::varProblems($v) as $problem) {
                $p[] = "$name: $problem";
            }
        }
        $mounts = [];
        $pathEnvs = [];
        foreach ($c->sortedFiles() as $name => $f) {
            foreach (self::fileProblems($f, $c) as $problem) {
                $p[] = "file $name: $problem";
            }
            if ($f->pathEnv !== null) {
                if (isset($pathEnvs[$f->pathEnv])) {
                    $p[] = "file $name: pathEnv {$f->pathEnv} is also used by file {$pathEnvs[$f->pathEnv]}";
                }
                $pathEnvs[$f->pathEnv] = $name;
            }
            $dir = $f->type === 'tls' ? $f->path : dirname($f->path);
            if ($f->path !== '' && isset($mounts[$dir])) {
                $p[] = "file $name: is mounted at $dir, as file {$mounts[$dir]} is; mounts would hide each other";
            }
            $mounts[$dir] = $name;
        }
        return $p;
    }

    /** @return list<string> */
    public static function warnings(ContractSpec $c): array
    {
        $w = [];
        foreach ($c->sortedVars() as $name => $v) {
            if (preg_match('/^(FF|FEATURE|FEATURE_FLAG|ENABLE)_/', $name)) {
                $w[] = "$name looks like a feature flag; flags that change without a rollout belong in a flag service, not the contract (SPEC §10)";
            }
        }
        return $w;
    }

    /** @return list<string> */
    private static function varProblems(VarSpec $v): array
    {
        $p = [];
        if (!preg_match(self::ENV_NAME, $v->name)) {
            $p[] = 'name must match ^[A-Z][A-Z0-9_]*$';
        }
        if (!in_array($v->type, VarSpec::TYPES, true)) {
            return [...$p, "unknown type \"{$v->type}\""];
        }
        if (mb_strlen(trim($v->description)) < 5) {
            $p[] = 'needs a description of at least 5 characters';
        }
        if ($v->required && $v->hasDefault) {
            $p[] = 'a required variable cannot have a default; the platform must supply it';
        }
        if ($v->secret && $v->hasDefault) {
            $p[] = 'a secret cannot have a default';
        }
        if ($v->secret && $v->examples !== null) {
            $p[] = 'a secret cannot have examples';
        }
        if ($v->deprecated !== null && isset($v->deprecated['replacedBy']) && !preg_match(self::ENV_NAME, $v->deprecated['replacedBy'])) {
            $p[] = 'deprecated.replacedBy must be a variable name';
        }
        foreach (['minLength', 'maxLength', 'minItems', 'maxItems'] as $field) {
            if ($v->{$field} !== null && $v->{$field} < 0) {
                $p[] = "$field cannot be negative";
            }
        }
        $only = [
            'minLength' => ['string'], 'maxLength' => ['string'], 'pattern' => ['string'],
            'min' => ['int', 'float', 'duration'], 'max' => ['int', 'float', 'duration'],
            'schemes' => ['url'], 'values' => ['enum'], 'minItems' => ['list'], 'maxItems' => ['list'],
            'itemMin' => ['list'], 'itemMax' => ['list'], 'schema' => ['json'], 'encoding' => ['duration', 'list'],
        ];
        foreach ($only as $field => $types) {
            if ($v->{$field} !== null && !in_array($v->type, $types, true)) {
                $p[] = "$field does not apply to a {$v->type} variable";
            }
        }
        if ($v->pattern !== null && ($err = Re2::check($v->pattern)) !== null) {
            $p[] = "pattern is not RE2: $err";
        }
        if ($v->minLength !== null && $v->maxLength !== null && $v->minLength > $v->maxLength) {
            $p[] = 'minLength is greater than maxLength';
        }
        switch ($v->type) {
            case 'int':
                foreach (['min', 'max'] as $f) {
                    if ($v->{$f} !== null && !is_int($v->{$f})) {
                        $p[] = "$f must be an integer";
                    }
                }
                break;
            case 'float':
                foreach (['min', 'max'] as $f) {
                    if ($v->{$f} !== null && (!is_int($v->{$f}) && !is_float($v->{$f}) || is_float($v->{$f}) && !is_finite($v->{$f}))) {
                        $p[] = "$f must be a finite number";
                    }
                }
                break;
            case 'duration':
                foreach (['min', 'max'] as $f) {
                    if ($v->{$f} !== null && !$v->{$f} instanceof Duration) {
                        $p[] = "$f must be a duration";
                    }
                }
                if (!in_array($v->encoding(), Duration::ENCODINGS, true)) {
                    $p[] = 'encoding must be one of ' . implode(', ', Duration::ENCODINGS);
                }
                break;
            case 'enum':
                if ($v->values === null || $v->values === []) {
                    $p[] = 'an enum needs at least one value';
                }
                break;
            case 'url':
                if ($v->schemes === []) {
                    $p[] = 'schemes cannot be empty';
                }
                break;
            case 'list':
                if (!in_array($v->items, ['string', 'int'], true)) {
                    $p[] = 'list items must be "string" or "int"';
                }
                if (!in_array($v->encoding(), VarSpec::LIST_ENCODINGS, true)) {
                    $p[] = 'encoding must be one of ' . implode(', ', VarSpec::LIST_ENCODINGS);
                }
                if ($v->encoding() === 'csv' && $v->separator === '') {
                    $p[] = 'separator cannot be empty';
                }
                if (($v->itemMin !== null || $v->itemMax !== null) && $v->items !== 'int') {
                    $p[] = 'itemMin and itemMax only apply to a list of int items';
                }
                if ($v->minItems !== null && $v->maxItems !== null && $v->minItems > $v->maxItems) {
                    $p[] = 'minItems is greater than maxItems';
                }
                break;
        }
        if (in_array($v->type, ['int', 'float', 'duration'], true) && $v->min !== null && $v->max !== null) {
            $lo = $v->min instanceof Duration ? $v->min->nanoseconds : $v->min;
            $hi = $v->max instanceof Duration ? $v->max->nanoseconds : $v->max;
            if ($lo > $hi) {
                $p[] = 'min is greater than max';
            }
        }
        if ($p === [] && $v->hasDefault) {
            $problem = VarParser::check($v, $v->default);
            if ($problem !== null) {
                $p[] = "default does not satisfy its own constraints: {$problem[1]}";
            }
        }
        return $p;
    }

    /** @return list<string> */
    private static function fileProblems(FileSpec $f, ContractSpec $c): array
    {
        $p = [];
        if (!preg_match(self::INPUT_NAME, $f->name)) {
            $p[] = 'name must be a DNS label of at most 42 characters, such as serving-tls';
        }
        if (!in_array($f->type, FileSpec::TYPES, true)) {
            return [...$p, "unknown type \"{$f->type}\""];
        }
        if (mb_strlen(trim($f->description)) < 5) {
            $p[] = 'needs a description of at least 5 characters';
        }
        if (!preg_match('#^/[A-Za-z0-9._/-]+$#D', $f->path) || preg_match('#(^|/)\.\.?(/|$)#', $f->path) || str_contains($f->path, '//') || str_ends_with($f->path, '/')) {
            $p[] = "path \"{$f->path}\" must be absolute and normalised";
        } else {
            $dir = $f->type === 'tls' ? $f->path : dirname($f->path);
            if (in_array($dir, self::RESERVED_DIRS, true)) {
                $p[] = "would be mounted at $dir, hiding what the image has there; use a directory of its own";
            }
        }
        if ($f->pathEnv !== null) {
            if (!preg_match(self::ENV_NAME, $f->pathEnv)) {
                $p[] = 'pathEnv must match ^[A-Z][A-Z0-9_]*$';
            } elseif (isset($c->vars[$f->pathEnv])) {
                $p[] = "pathEnv {$f->pathEnv} is also declared as a variable";
            }
        }
        if ($f->reload === 'watch') {
            $p[] = 'reload "watch" is not supported: this SDK reads files at boot, so declare reload "restart" and let the platform roll the pods';
        } elseif ($f->reload !== 'restart') {
            $p[] = 'reload must be "restart" or "watch"';
        }
        if ($f->maxSize !== null && $f->maxSize <= 0) {
            $p[] = 'maxSize must be positive';
        }
        if (($f->type === 'tls' || $f->type === 'keystore') && !$f->secret) {
            $p[] = "a {$f->type} input is always secret";
        }
        switch ($f->type) {
            case 'config':
                if (!in_array($f->format, ['json', 'yaml', 'toml'], true)) {
                    $p[] = 'format must be json, yaml or toml';
                } elseif ($f->format === 'yaml' && !class_exists(\Symfony\Component\Yaml\Yaml::class)) {
                    $p[] = 'reading YAML needs symfony/yaml: composer require symfony/yaml';
                } elseif ($f->format === 'toml' && !class_exists(\Devium\Toml\Toml::class)) {
                    $p[] = 'reading TOML needs devium/toml: composer require devium/toml';
                }
                break;
            case 'keystore':
                if (!in_array($f->format, ['pkcs12', 'jks'], true)) {
                    $p[] = 'format must be pkcs12 or jks';
                }
                if ($f->passwordVar !== null) {
                    $pw = $c->vars[$f->passwordVar] ?? null;
                    if ($pw === null || !$pw->secret) {
                        $p[] = "passwordVar {$f->passwordVar} must be a declared secret variable";
                    }
                }
                break;
            case 'tls':
                foreach ($f->keyAlgorithms ?? [] as $alg) {
                    if (!in_array($alg, ['RSA', 'ECDSA', 'Ed25519'], true)) {
                        $p[] = "unknown key algorithm \"$alg\"; use RSA, ECDSA or Ed25519";
                    }
                }
                if ($f->dnsNames === []) {
                    $p[] = 'dnsNames cannot be empty';
                }
                break;
            case 'caBundle':
                if ($f->minCertificates < 1) {
                    $p[] = 'minCertificates must be at least 1';
                }
                break;
            case 'text':
                if ($f->pattern !== null && ($err = Re2::check($f->pattern)) !== null) {
                    $p[] = "pattern is not RE2: $err";
                }
                break;
        }
        return $p;
    }
}
