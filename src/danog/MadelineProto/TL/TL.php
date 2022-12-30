<?php declare(strict_types=1);

/**
 * TL module.
 *
 * This file is part of MadelineProto.
 * MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU General Public License along with MadelineProto.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2020 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 * @link https://docs.madelineproto.xyz MadelineProto documentation
 */

namespace danog\MadelineProto\TL;

use Amp\File\File;
use Amp\Ipc\Sync\ChannelledSocket;
use Amp\Promise;
use danog\MadelineProto\Lang;
use danog\MadelineProto\Logger;
use danog\MadelineProto\MTProto;
use danog\MadelineProto\MTProto\OutgoingMessage;
use danog\MadelineProto\SecurityException;
use danog\MadelineProto\Settings\TLSchema;
use danog\MadelineProto\Stream\StreamInterface;
use danog\MadelineProto\TL\Types\Button;
use danog\MadelineProto\TL\Types\Bytes;
use danog\MadelineProto\Tools;
use Generator;

use const STR_PAD_LEFT;

/**
 * TL serialization.
 */
class TL
{
    /**
     * Highest available secret chat layer version.
     *
     * @var integer
     */
    private $secretLayer = -1;
    /**
     * Constructors.
     *
     * @var TLConstructors
     */
    private $constructors;
    /**
     * Methods.
     *
     * @var TLMethods
     */
    private $methods;
    /**
     * TD Constructors.
     *
     * @var TLConstructors
     */
    private $tdConstructors;
    /**
     * TD Methods.
     *
     * @var TLMethods
     */
    private $tdMethods;
    /**
     * Descriptions.
     *
     * @var array
     */
    private $tdDescriptions;
    /**
     * TL callbacks.
     *
     * @var array
     */
    private $callbacks = [];
    /**
     * API instance.
     *
     * @var MTProto
     */
    private $API;
    /**
     * Constructor function.
     *
     * @param MTProto $API API instance
     */
    public function __construct(MTProto $API = null)
    {
        $this->API = $API;
    }
    /**
     * Get secret chat layer version.
     */
    public function getSecretLayer(): int
    {
        return $this->secretLayer;
    }
    /**
     * Get constructors.
     */
    public function getConstructors(bool $td = false): TLConstructors
    {
        return $td ? $this->tdConstructors : $this->constructors;
    }
    /**
     * Get methods.
     */
    public function getMethods(bool $td = false): TLMethods
    {
        return $td ? $this->tdMethods : $this->methods;
    }
    /**
     * Get TL descriptions.
     */
    public function &getDescriptions(): array
    {
        return $this->tdDescriptions;
    }
    /**
     * Initialize TL parser.
     *
     * @param TLSchema     $files   Scheme files
     * @param TLCallback[] $objects TL Callback objects
     */
    public function init(TLSchema $files, array $objects = []): void
    {
        $this->API->logger->logger(Lang::$current_lang['TL_loading'], Logger::VERBOSE);
        $this->updateCallbacks($objects);
        $this->constructors = new TLConstructors();
        $this->methods = new TLMethods();
        $this->tdConstructors = new TLConstructors();
        $this->tdMethods = new TLMethods();
        $this->tdDescriptions = ['types' => [], 'constructors' => [], 'methods' => []];
        foreach (\array_filter([
            'api' => $files->getAPISchema(),
            'mtproto' => $files->getMTProtoSchema(),
            'secret' => $files->getSecretSchema(),
            ...$files->getOther(),
        ]) as $scheme_type => $file) {
            $this->API->logger->logger(\sprintf(Lang::$current_lang['file_parsing'], \basename($file)), Logger::VERBOSE);
            $filec = \file_get_contents(Tools::absolute($file));
            $TL_dict = \json_decode($filec, true);
            if ($TL_dict === null) {
                $TL_dict = ['methods' => [], 'constructors' => []];
                $type = 'constructors';
                $layer = null;
                $tl_file = \explode("\n", $filec);
                $key = 0;
                $e = null;
                $class = null;
                $dparams = [];
                $lineBuf = '';
                foreach ($tl_file as $line) {
                    $line = \rtrim($line);
                    if (\preg_match('|^//@|', $line)) {
                        $list = \explode(' @', \str_replace('//', ' ', $line));
                        foreach ($list as $elem) {
                            if ($elem === '') {
                                continue;
                            }
                            $elem = \explode(' ', $elem, 2);
                            if ($elem[0] === 'class') {
                                $elem = \explode(' ', $elem[1], 2);
                                $class = $elem[0];
                                continue;
                            }
                            if ($elem[0] === 'description') {
                                if (!\is_null($class)) {
                                    $this->tdDescriptions['types'][$class] = $elem[1];
                                    $class = null;
                                } else {
                                    $e = $elem[1];
                                }
                                continue;
                            }
                            if ($elem[0] === 'param_description') {
                                $elem[0] = 'description';
                            }
                            $dparams[$elem[0]] = $elem[1];
                        }
                        continue;
                    }
                    $line = \preg_replace(['|//.*|', '|^\\s+$|'], '', $line);
                    if ($line === '') {
                        continue;
                    }
                    if ($line === '---functions---') {
                        $type = 'methods';
                        continue;
                    }
                    if ($line === '---types---') {
                        $type = 'constructors';
                        continue;
                    }
                    if (\preg_match('|^===(\\d*)===|', $line, $matches)) {
                        $layer = (int) $matches[1];
                        continue;
                    }
                    if (\strpos($line, 'vector#') === 0) {
                        continue;
                    }
                    if (\strpos($line, ' ?= ') !== false) {
                        continue;
                    }
                    $line = \preg_replace(['/[(]([\\w\\.]+) ([\\w\\.]+)[)]/', '/\\s+/'], ['$1<$2>', ' '], $line);
                    if (\strpos($line, ';') === false) {
                        $lineBuf .= $line;
                        continue;
                    } elseif ($lineBuf) {
                        $lineBuf .= $line;
                        $line = $lineBuf;
                        $lineBuf = '';
                    }
                    $name = \preg_replace(['/#.*/', '/\\s.*/'], '', $line);
                    if (\in_array($name, ['bytes', 'int128', 'int256', 'int512', 'int', 'long', 'double', 'string', 'bytes', 'object', 'function'])) {
                        /*if (!(\in_array($scheme_type, ['ton_api', 'lite_api']) && $name === 'bytes')) {
                              continue;
                          }*/
                        continue;
                    }
                    if (\in_array($scheme_type, ['ton_api', 'lite_api'])) {
                        $clean = \preg_replace(['/;/', '/#[a-f0-9]+ /', '/ [a-zA-Z0-9_]+\\:flags\\.[0-9]+\\?true/', '/[<]/', '/[>]/', '/  /', '/^ /', '/ $/', '/{/', '/}/'], ['', ' ', '', ' ', ' ', ' ', '', '', '', ''], $line);
                    } else {
                        $clean = \preg_replace(['/:bytes /', '/;/', '/#[a-f0-9]+ /', '/ [a-zA-Z0-9_]+\\:flags\\.[0-9]+\\?true/', '/[<]/', '/[>]/', '/  /', '/^ /', '/ $/', '/\\?bytes /', '/{/', '/}/'], [':string ', '', ' ', '', ' ', ' ', ' ', '', '', '?string ', '', ''], $line);
                    }
                    $id = \hash('crc32b', $clean);
                    if (\preg_match('/^[^\\s]+#([a-f0-9]*)/i', $line, $matches)) {
                        $nid = \str_pad($matches[1], 8, '0', STR_PAD_LEFT);
                        if ($id !== $nid) {
                            $this->API->logger->logger(\sprintf(Lang::$current_lang['crc32_mismatch'], $id, $nid, $line), Logger::ERROR);
                        }
                        $id = $nid;
                    }
                    if (!\is_null($e)) {
                        $this->tdDescriptions[$type][$name] = ['description' => $e, 'params' => $dparams];
                        $e = null;
                        $dparams = [];
                    }
                    $TL_dict[$type][$key][$type === 'constructors' ? 'predicate' : 'method'] = $name;
                    $TL_dict[$type][$key]['id'] = $a = \strrev(\hex2bin($id));
                    $TL_dict[$type][$key]['params'] = [];
                    $TL_dict[$type][$key]['type'] = \preg_replace(['/.+\\s+=\\s+/', '/;/'], '', $line);
                    if ($layer !== null) {
                        $TL_dict[$type][$key]['layer'] = $layer;
                    }
                    if ($name !== 'vector' && $TL_dict[$type][$key]['type'] !== 'Vector t') {
                        foreach (\explode(' ', \preg_replace(['/^[^\\s]+\\s/', '/=\\s[^\\s]+/', '/\\s$/'], '', $line)) as $param) {
                            if ($param === '') {
                                continue;
                            }
                            if ($param[0] === '{') {
                                continue;
                            }
                            if ($param === '#') {
                                continue;
                            }
                            $explode = \explode(':', $param);
                            $TL_dict[$type][$key]['params'][] = ['name' => $explode[0], 'type' => $explode[1]];
                        }
                    }
                    $key++;
                }
            } else {
                foreach ($TL_dict['constructors'] as $key => $value) {
                    $TL_dict['constructors'][$key]['id'] = Tools::packSignedInt($TL_dict['constructors'][$key]['id']);
                }
                foreach ($TL_dict['methods'] as $key => $value) {
                    $TL_dict['methods'][$key]['id'] = Tools::packSignedInt($TL_dict['methods'][$key]['id']);
                }
            }

            if (empty($TL_dict) || empty($TL_dict['constructors']) || !isset($TL_dict['methods'])) {
                throw new Exception(Lang::$current_lang['src_file_invalid'].$file);
            }
            $this->API->logger->logger(Lang::$current_lang['translating_obj'], Logger::ULTRA_VERBOSE);
            foreach ($TL_dict['constructors'] as $elem) {
                if ($scheme_type === 'secret') {
                    $this->secretLayer = \max($this->secretLayer, $elem['layer']);
                }
                $this->{$scheme_type === 'td' ? 'tdConstructors' : 'constructors'}->add($elem, $scheme_type);
            }
            $this->API->logger->logger(Lang::$current_lang['translating_methods'], Logger::ULTRA_VERBOSE);
            foreach ($TL_dict['methods'] as $elem) {
                $this->{$scheme_type === 'td' ? 'tdMethods' : 'methods'}->add($elem);
                if ($scheme_type === 'secret') {
                    $this->secretLayer = \max($this->secretLayer, $elem['layer']);
                }
            }
        }
        if (isset($files->getOther()['td'])) {
            foreach ($this->tdConstructors->by_id as $id => $data) {
                $name = $data['predicate'];
                if ($this->constructors->findById($id) === false) {
                    unset($this->tdDescriptions['constructors'][$name]);
                } else {
                    if (!\count($this->tdDescriptions['constructors'][$name]['params'])) {
                        continue;
                    }
                    foreach ($this->tdDescriptions['constructors'][$name]['params'] as $k => $param) {
                        $this->tdDescriptions['constructors'][$name]['params'][$k] = \str_replace('nullable', 'optional', $param);
                    }
                }
            }
            foreach ($this->tdMethods->by_id as $id => $data) {
                $name = $data['method'];
                if ($this->methods->findById($id) === false) {
                    unset($this->tdDescriptions['methods'][$name]);
                } else {
                    foreach ($this->tdDescriptions['methods'][$name]['params'] as $k => $param) {
                        $this->tdDescriptions['constructors'][$name]['params'][$k] = \str_replace('nullable', 'optional', $param);
                    }
                }
            }
        }
        $files->upgrade();
    }
    /**
     * Get TL namespaces.
     */
    public function getMethodNamespaces(): array
    {
        $res = [];
        foreach ($this->methods->method_namespace as $pair) {
            $a = \key($pair);
            $res[$a] = $a;
        }
        return $res;
    }
    /**
     * Get namespaced methods (method => namespace).
     */
    public function getMethodsNamespaced(): array
    {
        return $this->methods->method_namespace;
    }
    /**
     * Update TL callbacks.
     *
     * @param TLCallback[] $objects TL callbacks
     */
    public function updateCallbacks(array $objects): void
    {
        $this->callbacks = [];
        foreach ($objects as $object) {
            if (!isset(\class_implements(\get_class($object))[TLCallback::class])) {
                throw new Exception('Invalid callback object provided!');
            }
            $new = [TLCallback::METHOD_BEFORE_CALLBACK => $object->getMethodBeforeCallbacks(), TLCallback::METHOD_CALLBACK => $object->getMethodCallbacks(), TLCallback::CONSTRUCTOR_BEFORE_CALLBACK => $object->getConstructorBeforeCallbacks(), TLCallback::CONSTRUCTOR_CALLBACK => $object->getConstructorCallbacks(), TLCallback::CONSTRUCTOR_SERIALIZE_CALLBACK => $object->getConstructorSerializeCallbacks(), TLCallback::TYPE_MISMATCH_CALLBACK => $object->getTypeMismatchCallbacks()];
            foreach ($new as $type => $values) {
                foreach ($values as $match => $callback) {
                    if (!isset($this->callbacks[$type][$match])) {
                        $this->callbacks[$type][$match] = [];
                    }
                    if (\in_array($type, [TLCallback::TYPE_MISMATCH_CALLBACK, TLCallback::CONSTRUCTOR_SERIALIZE_CALLBACK])) {
                        $this->callbacks[$type][$match] = $callback;
                    } else {
                        $this->callbacks[$type][$match] = \array_merge($callback, $this->callbacks[$type][$match]);
                    }
                }
            }
        }
    }
    /**
     * Deserialize bool.
     *
     * @param string $id Constructor ID
     */
    private function deserializeBool(string $id): bool
    {
        $tl_elem = $this->constructors->findById($id);
        if ($tl_elem === false) {
            throw new Exception(Lang::$current_lang['bool_error']);
        }
        return $tl_elem['predicate'] === 'boolTrue';
    }
    /**
     * Serialize TL object.
     *
     * @param array   $type   TL type definition
     * @param mixed   $object Object to serialize
     * @param string  $ctx    Context
     * @param integer $layer  Layer version
     * @psalm-return Generator<(int|mixed), (array|mixed), mixed, (false|mixed|null|string)>
     */
    public function serializeObject(array $type, $object, string $ctx, int $layer = -1): Generator
    {
        if ($object instanceof Generator) {
            $object = yield from $object;
        }
        switch ($type['type']) {
            case 'int':
                if (!\is_numeric($object)) {
                    throw new Exception(Lang::$current_lang['not_numeric']);
                }
                return Tools::packSignedInt($object);
            case '#':
                if (!\is_numeric($object)) {
                    throw new Exception(Lang::$current_lang['not_numeric']);
                }
                return Tools::packUnsignedInt($object);
            case 'long':
                if (\is_object($object)) {
                    return \str_pad(\strrev($object->toBytes()), 8, \chr(0));
                }
                if (\is_string($object) && \strlen($object) === 8) {
                    return $object;
                }
                if (\is_string($object) && \strlen($object) === 9 && $object[0] === 'a') {
                    return \substr($object, 1);
                }
                if (\is_array($object) && $type['name'] === 'hash') {
                    return Tools::genVectorHash($object);
                }
                if (\is_array($object) && \count($object) === 2) {
                    return \pack('l2', ...$object); // For bot API on 32bit
                }
                if (!\is_numeric($object)) {
                    throw new Exception(Lang::$current_lang['not_numeric']);
                }
                return Tools::packSignedLong($object);
            case 'int128':
                if (\strlen($object) !== 16) {
                    $object = \base64_decode($object);
                    if (\strlen($object) !== 16) {
                        throw new Exception(Lang::$current_lang['long_not_16']);
                    }
                }
                return (string) $object;
            case 'int256':
                if (\strlen($object) !== 32) {
                    $object = \base64_decode($object);
                    if (\strlen($object) !== 32) {
                        throw new Exception(Lang::$current_lang['long_not_32']);
                    }
                }
                return (string) $object;
            case 'int512':
                if (\strlen($object) !== 64) {
                    $object = \base64_decode($object);
                    if (\strlen($object) !== 64) {
                        throw new Exception(Lang::$current_lang['long_not_64']);
                    }
                }
                return (string) $object;
            case 'double':
                return Tools::packDouble($object);
            case 'string':
                if (!\is_string($object)) {
                    throw new Exception("You didn't provide a valid string");
                }
                //$object = \pack('C*', ...\unpack('C*', $object));
                $l = \strlen($object);
                $concat = '';
                if ($l <= 253) {
                    $concat .= \chr($l);
                    $concat .= $object;
                    $concat .= \pack('@'.Tools::posmod(-$l - 1, 4));
                } else {
                    $concat .= \chr(254);
                    $concat .= \substr(Tools::packSignedInt($l), 0, 3);
                    $concat .= $object;
                    $concat .= \pack('@'.Tools::posmod(-$l, 4));
                }
                return $concat;
            case 'bytes':
                if (\is_array($object) && isset($object['_']) && $object['_'] === 'bytes') {
                    $object = \base64_decode($object['bytes']);
                }
                if (!\is_string($object) && !$object instanceof Bytes) {
                    throw new Exception("You didn't provide a valid string");
                }
                $l = \strlen($object);
                $concat = '';
                if ($l <= 253) {
                    $concat .= \chr($l);
                    $concat .= $object;
                    $concat .= \pack('@'.Tools::posmod(-$l - 1, 4));
                } else {
                    $concat .= \chr(254);
                    $concat .= \substr(Tools::packSignedInt($l), 0, 3);
                    $concat .= $object;
                    $concat .= \pack('@'.Tools::posmod(-$l, 4));
                }
                return $concat;
            case 'Bool':
                return $this->constructors->findByPredicate((bool) $object ? 'boolTrue' : 'boolFalse')['id'];
            case 'true':
                return;
            case '!X':
                return $object;
            case 'Vector t':
                if (!\is_array($object)) {
                    throw new Exception(Lang::$current_lang['array_invalid']);
                }
                if (isset($object['_'])) {
                    throw new Exception('You must provide an array of '.$type['subtype'].' objects, not a '.$type['subtype']." object. Example: [['_' => ".$type['subtype'].', ... ]]');
                }
                $concat = $this->constructors->findByPredicate('vector')['id'];
                $concat .= Tools::packUnsignedInt(\count($object));
                foreach ($object as $k => $current_object) {
                    $concat .= (yield from $this->serializeObject(['type' => $type['subtype']], $current_object, $k, $layer));
                }
                return $concat;
            case 'vector':
                if (!\is_array($object)) {
                    throw new Exception(Lang::$current_lang['array_invalid']);
                }
                $concat = Tools::packUnsignedInt(\count($object));
                foreach ($object as $k => $current_object) {
                    $concat .= (yield from $this->serializeObject(['type' => $type['subtype']], $current_object, $k, $layer));
                }
                return $concat;
            case 'Object':
                if (\is_string($object)) {
                    return $object;
                }
        }
        if ($type['type'] === 'InputMessage' && !\is_array($object)) {
            $object = ['_' => 'inputMessageID', 'id' => $object];
        } elseif (isset($this->callbacks[TLCallback::TYPE_MISMATCH_CALLBACK][$type['type']]) && (!\is_array($object) || isset($object['_']) && $this->constructors->findByPredicate($object['_'])['type'] !== $type['type'])) {
            $object = $this->callbacks[TLCallback::TYPE_MISMATCH_CALLBACK][$type['type']]($object);
            $object = $object instanceof Generator ? yield from $object : yield $object;
            if (!isset($object['_'])) {
                if (!isset($object[$type['type']])) {
                    throw new \danog\MadelineProto\Exception("Could not convert {$type['type']} object");
                }
                $object = $object[$type['type']];
            }
        }
        if (!isset($object['_'])) {
            $constructorData = $this->constructors->findByPredicate($type['type'], $layer);
            if ($constructorData === false) {
                throw new Exception(Lang::$current_lang['predicate_not_set']);
            }
            $auto = true;
            $object['_'] = $constructorData['predicate'];
        }
        if (isset($this->callbacks[TLCallback::CONSTRUCTOR_SERIALIZE_CALLBACK][$object['_']])) {
            $object = yield $this->callbacks[TLCallback::CONSTRUCTOR_SERIALIZE_CALLBACK][$object['_']]($object);
        }
        $predicate = $object['_'];
        $constructorData = $this->constructors->findByPredicate($predicate, $layer);
        if ($constructorData === false) {
            $this->API->logger->logger($object, Logger::FATAL_ERROR);
            throw new Exception(\sprintf(Lang::$current_lang['type_extract_error'], $predicate));
        }
        if ($bare = $type['type'] != '' && $type['type'][0] === '%') {
            $type['type'] = \substr($type['type'], 1);
        }
        if ($predicate === $type['type']) {
            $bare = true;
        }
        if ($predicate === 'messageEntityMentionName') {
            $constructorData = $this->constructors->findByPredicate('inputMessageEntityMentionName');
        }
        $concat = $bare ? '' : $constructorData['id'];
        return $concat.(yield from $this->serializeParams($constructorData, $object, '', $layer, null));
    }
    /**
     * Serialize method.
     *
     * @param string $method    Method name
     * @param mixed  $arguments Arguments
     * @psalm-return Generator<(int|mixed), (Promise|Promise<File>|Promise<ChannelledSocket>|Promise<int>|Promise<mixed>|Promise<(null|string)>|Promise<string>|StreamInterface|array|int|mixed), mixed, string>
     */
    public function serializeMethod(string $method, $arguments): Generator
    {
        $tl = $this->methods->findByMethod($method);
        if ($tl === false) {
            throw new Exception(Lang::$current_lang['method_not_found'].$method);
        }
        return $tl['id'].(yield from $this->serializeParams($tl, $arguments, $method, -1, $arguments['queuePromise'] ?? null));
    }
    /**
     * Serialize parameters.
     *
     * @param array   $tl        TL object definition
     * @param array   $arguments Arguments
     * @param string  $ctx       Context
     * @param integer $layer     Layer
     * @psalm-return Generator<(int|mixed), (Promise|Promise<File>|Promise<ChannelledSocket>|Promise<int>|Promise<mixed>|Promise<(null|string)>|StreamInterface|array|int|mixed), mixed, string>
     */
    private function serializeParams(array $tl, array $arguments, string $ctx, int $layer, $promise): Generator
    {
        $serialized = '';
        $arguments = $this->API->botAPIToMTProto($arguments instanceof Button ? $arguments->jsonSerialize() : $arguments);
        foreach ($tl['params'] as $cur_flag) {
            if (isset($cur_flag['pow'])) {
                $arguments[$cur_flag['flag']] ??= 0;
                switch ($cur_flag['type']) {
                    case 'true':
                        $arguments[$cur_flag['flag']] = isset($arguments[$cur_flag['name']]) && $arguments[$cur_flag['name']] ? $arguments[$cur_flag['flag']] | $cur_flag['pow'] : $arguments[$cur_flag['flag']] & ~$cur_flag['pow'];
                        unset($arguments[$cur_flag['name']]);
                        break;
                    case 'Bool':
                        $arguments[$cur_flag['name']] = isset($arguments[$cur_flag['name']]) && $arguments[$cur_flag['name']] && ($arguments[$cur_flag['flag']] & $cur_flag['pow']) != 0;
                        if (($arguments[$cur_flag['flag']] & $cur_flag['pow']) === 0) {
                            unset($arguments[$cur_flag['name']]);
                        }
                        break;
                    default:
                        $arguments[$cur_flag['flag']] = isset($arguments[$cur_flag['name']]) && $arguments[$cur_flag['name']] !== null ? $arguments[$cur_flag['flag']] | $cur_flag['pow'] : $arguments[$cur_flag['flag']] & ~$cur_flag['pow'];
                        break;
                }
            }
        }
        foreach ($tl['params'] as $current_argument) {
            if (!isset($arguments[$current_argument['name']])) {
                if (isset($current_argument['pow']) && ($current_argument['type'] === 'true' || ($arguments[$current_argument['flag']] & $current_argument['pow']) === 0)) {
                    //$this->API->logger->logger('Skipping '.$current_argument['name'].' of type '.$current_argument['type');
                    continue;
                }
                if ($current_argument['name'] === 'random_bytes') {
                    $serialized .= yield from $this->serializeObject(['type' => 'bytes'], Tools::random(15 + 4 * Tools::randomInt($modulus = 3)), 'random_bytes');
                    continue;
                }
                if ($current_argument['name'] === 'data' && isset($tl['method']) && \in_array($tl['method'], ['messages.sendEncrypted', 'messages.sendEncryptedFile', 'messages.sendEncryptedService']) && isset($arguments['message'])) {
                    $serialized .= yield from $this->serializeObject($current_argument, yield from $this->API->encryptSecretMessage($arguments['peer']['chat_id'], $arguments['message'], $promise), 'data');
                    continue;
                }
                if ($current_argument['name'] === 'random_id') {
                    switch ($current_argument['type']) {
                        case 'long':
                            $serialized .= Tools::random(8);
                            continue 2;
                        case 'int':
                            $serialized .= Tools::random(4);
                            continue 2;
                        case 'Vector t':
                            if (isset($arguments['id'])) {
                                $serialized .= $this->constructors->findByPredicate('vector')['id'];
                                $serialized .= Tools::packUnsignedInt(\count($arguments['id']));
                                $serialized .= Tools::random(8 * \count($arguments['id']));
                                continue 2;
                            }
                    }
                }
                if ($current_argument['name'] === 'hash' && $current_argument['type'] === 'long') {
                    $serialized .= \pack('@8');
                    continue;
                }
                if ($current_argument['name'] === 'hash' && $current_argument['type'] === 'int') {
                    $serialized .= \pack('@4');
                    continue;
                }
                if ($current_argument['name'] === 'flags' && $current_argument['type'] === 'int') {
                    $serialized .= \pack('@4');
                    continue;
                }
                if ($tl['type'] === 'InputMedia' && $current_argument['name'] === 'mime_type') {
                    $serialized .= (yield from $this->serializeObject($current_argument, $arguments['file']['mime_type'], $current_argument['name'], $layer));
                    continue;
                }
                if ($tl['type'] === 'DocumentAttribute' && \in_array($current_argument['name'], ['w', 'h', 'duration'])) {
                    $serialized .= \pack('@4');
                    continue;
                }
                if (\in_array($current_argument['type'], ['bytes', 'string', 'int'])) {
                    $serialized .= \pack('@4');
                    continue;
                }
                if (($id = $this->constructors->findByPredicate(\lcfirst($current_argument['type']).'Empty', $tl['layer'] ?? -1)) && $id['type'] === $current_argument['type']) {
                    $serialized .= $id['id'];
                    continue;
                }
                if (($id = $this->constructors->findByPredicate('input'.$current_argument['type'].'Empty', $tl['layer'] ?? -1)) && $id['type'] === $current_argument['type']) {
                    $serialized .= $id['id'];
                    continue;
                }
                switch ($current_argument['type']) {
                    case 'Vector t':
                    case 'vector':
                        $arguments[$current_argument['name']] = [];
                        break;
                    case 'DataJSON':
                    case '%DataJSON':
                        $arguments[$current_argument['name']] = null;
                        break;
                    default:
                        throw new Exception("Missing required parameter ".$current_argument['name']);
                }
            }
            if (\in_array($current_argument['type'], ['DataJSON', '%DataJSON'])) {
                $arguments[$current_argument['name']] = ['_' => 'dataJSON', 'data' => \json_encode($arguments[$current_argument['name']])];
            }
            if (isset($current_argument['subtype']) && \in_array($current_argument['subtype'], ['DataJSON', '%DataJSON'])) {
                \array_walk($arguments[$current_argument['name']], function (&$arg): void {
                    $arg = ['_' => 'dataJSON', 'data' => \json_encode($arg)];
                });
            }
            if ($current_argument['type'] === 'InputFile' && (!\is_array($arguments[$current_argument['name']]) || !(isset($arguments[$current_argument['name']]['_']) && $this->constructors->findByPredicate($arguments[$current_argument['name']]['_'])['type'] === 'InputFile'))) {
                $arguments[$current_argument['name']] = (yield from $this->API->upload($arguments[$current_argument['name']]));
            }
            if ($current_argument['type'] === 'InputEncryptedChat' && (!\is_array($arguments[$current_argument['name']]) || isset($arguments[$current_argument['name']]['_']) && $this->constructors->findByPredicate($arguments[$current_argument['name']]['_'])['type'] !== $current_argument['type'])) {
                if (\is_array($arguments[$current_argument['name']])) {
                    $arguments[$current_argument['name']] = (yield from $this->API->getInfo($arguments[$current_argument['name']]))['InputEncryptedChat'];
                } else {
                    if (!$this->API->hasSecretChat($arguments[$current_argument['name']])) {
                        throw new \danog\MadelineProto\Exception(Lang::$current_lang['sec_peer_not_in_db']);
                    }
                    $arguments[$current_argument['name']] = $this->API->getSecretChat($arguments[$current_argument['name']])['InputEncryptedChat'];
                }
            }
            //$this->API->logger->logger('Serializing '.$current_argument['name'].' of type '.$current_argument['type');
            $serialized .= (yield from $this->serializeObject($current_argument, $arguments[$current_argument['name']], $current_argument['name'], $layer));
        }
        return $serialized;
    }
    /**
     * Get length of TL payload.
     *
     * @param resource|string $stream Stream
     * @param array           $type   Type identifier
     */
    public function getLength($stream, array $type = ['type' => '']): int
    {
        if (\is_string($stream)) {
            $res = \fopen('php://memory', 'rw+b');
            \fwrite($res, $stream);
            \fseek($res, 0);
            $stream = $res;
        } elseif (!\is_resource($stream)) {
            throw new Exception(Lang::$current_lang['stream_handle_invalid']);
        }
        $promises = [];
        $this->deserializeInternal($stream, $promises, $type);
        return \ftell($stream);
    }
    /**
     * Deserialize TL object.
     *
     * @param string|resource $stream Stream
     * @param array           $type   Type identifier
     * @psalm-return array{0: mixed, 1: Promise[]}
     */
    public function deserialize($stream, array $type = ['type' => '']): array
    {
        $promises = [];
        $result = $this->deserializeInternal($stream, $promises, $type);
        return [
            $result,
            $promises,
        ];
    }

    /**
     * Deserialize TL object.
     *
     * @param string|resource $stream    Stream
     * @param Promise[]       &$promises Promise array
     * @param array           $type      Type identifier
     */
    private function deserializeInternal($stream, array &$promises, array $type)
    {
        if (\is_string($stream)) {
            $res = \fopen('php://memory', 'rw+b');
            \fwrite($res, $stream);
            \fseek($res, 0);
            $stream = $res;
        } elseif (!\is_resource($stream)) {
            throw new Exception(Lang::$current_lang['stream_handle_invalid']);
        }
        switch ($type['type']) {
            case 'Bool':
                return $this->deserializeBool(\stream_get_contents($stream, 4));
            case 'int':
                return Tools::unpackSignedInt(\stream_get_contents($stream, 4));
            case '#':
                return \unpack('V', \stream_get_contents($stream, 4))[1];
            case 'long':
                if (isset($type['idstrlong'])) {
                    return \stream_get_contents($stream, 8);
                }
                return isset($type['strlong']) ? \stream_get_contents($stream, 8) : Tools::unpackSignedLong(\stream_get_contents($stream, 8));
            case 'double':
                return Tools::unpackDouble(\stream_get_contents($stream, 8));
            case 'int128':
                return \stream_get_contents($stream, 16);
            case 'int256':
                return \stream_get_contents($stream, 32);
            case 'int512':
                return \stream_get_contents($stream, 64);
            case 'string':
            case 'bytes':
                $l = \ord(\stream_get_contents($stream, 1));
                if ($l > 254) {
                    throw new Exception(Lang::$current_lang['length_too_big']);
                }
                if ($l === 254) {
                    $long_len = \unpack('V', \stream_get_contents($stream, 3).\chr(0))[1];
                    $x = \stream_get_contents($stream, $long_len);
                    $resto = Tools::posmod(-$long_len, 4);
                    if ($resto > 0) {
                        \stream_get_contents($stream, $resto);
                    }
                } else {
                    $x = $l ? \stream_get_contents($stream, $l) : '';
                    $resto = Tools::posmod(-($l + 1), 4);
                    if ($resto > 0) {
                        \stream_get_contents($stream, $resto);
                    }
                }
                if (!\is_string($x)) {
                    throw new Exception("Generated value isn't a string");
                }
                return $type['type'] === 'bytes' ? new Types\Bytes($x) : $x;
            case 'Vector t':
                $id = \stream_get_contents($stream, 4);
                $constructorData = $this->constructors->findById($id);
                if ($constructorData === false) {
                    $constructorData = $this->methods->findById($id);
                    $constructorData['predicate'] = 'method_'.$constructorData['method'];
                }
                if ($constructorData === false) {
                    throw new Exception(\sprintf(Lang::$current_lang['type_extract_error_id'], $type['type'], \bin2hex(\strrev($id))));
                }
                switch ($constructorData['predicate']) {
                    case 'gzip_packed':
                        return $this->deserializeInternal(
                            \gzdecode(
                                $this->deserializeInternal(
                                    $stream,
                                    $promises,
                                    ['type' => 'bytes', 'connection' => $type['connection']],
                                ),
                            ),
                            $promises,
                            ['type' => '', 'connection' => $type['connection']],
                        );
                    case 'Vector t':
                    case 'vector':
                        break;
                    default:
                        throw new Exception('Invalid vector constructor: '.$constructorData['predicate']);
                }
                // no break
            case 'vector':
                $count = \unpack('V', \stream_get_contents($stream, 4))[1];
                $result = [];
                $type['type'] = $type['subtype'];
                for ($i = 0; $i < $count; $i++) {
                    $result[] = $this->deserializeInternal($stream, $promises, $type);
                }
                return $result;
        }
        if ($type['type'] != '' && $type['type'][0] === '%') {
            $checkType = \substr($type['type'], 1);
            $constructorData = $this->constructors->findByType($checkType);
            if ($constructorData === false) {
                throw new Exception(Lang::$current_lang['constructor_not_found'].$checkType);
            }
        } else {
            $constructorData = $this->constructors->findByPredicate($type['type']);
            if ($constructorData === false) {
                $id = \stream_get_contents($stream, 4);
                $constructorData = $this->constructors->findById($id);
                if ($constructorData === false) {
                    $constructorData = $this->methods->findById($id);
                    if ($constructorData === false) {
                        throw new Exception(\sprintf(Lang::$current_lang['type_extract_error_id'], $type['type'], \bin2hex(\strrev($id))));
                    }
                    $constructorData['predicate'] = 'method_'.$constructorData['method'];
                }
            }
        }
        if ($constructorData['predicate'] === 'gzip_packed') {
            if (!isset($type['subtype'])) {
                $type['subtype'] = '';
            }
            return $this->deserializeInternal(
                \gzdecode(
                    $this->deserializeInternal(
                        $stream,
                        $promises,
                        ['type' => 'bytes'],
                    ),
                ),
                $promises,
                ['type' => '', 'connection' => $type['connection'], 'subtype' => $type['subtype']],
            );
        }
        if ($constructorData['type'] === 'Vector t') {
            $constructorData['connection'] = $type['connection'];
            $constructorData['subtype'] = $type['subtype'] ?? '';
            $constructorData['type'] = 'vector';
            return $this->deserializeInternal($stream, $promises, $constructorData);
        }
        if ($constructorData['predicate'] === 'boolTrue') {
            return true;
        }
        if ($constructorData['predicate'] === 'boolFalse') {
            return false;
        }
        $x = ['_' => $constructorData['predicate']];
        if (isset($this->callbacks[TLCallback::CONSTRUCTOR_BEFORE_CALLBACK][$x['_']])) {
            foreach ($this->callbacks[TLCallback::CONSTRUCTOR_BEFORE_CALLBACK][$x['_']] as $callback) {
                $callback($x['_']);
            }
        }
        foreach ($constructorData['params'] as $arg) {
            if (isset($arg['pow'])) {
                switch ($arg['type']) {
                    case 'true':
                        $x[$arg['name']] = ($x[$arg['flag']] & $arg['pow']) !== 0;
                        continue 2;
                    case 'Bool':
                        if (($x[$arg['flag']] & $arg['pow']) === 0) {
                            $x[$arg['name']] = false;
                            continue 2;
                        }
                        // no break
                    default:
                        if (($x[$arg['flag']] & $arg['pow']) === 0) {
                            continue 2;
                        }
                }
            }
            if (\in_array($arg['name'], ['msg_ids', 'msg_id', 'bad_msg_id', 'req_msg_id', 'answer_msg_id', 'first_msg_id'])) {
                $arg['idstrlong'] = true;
            }
            if (\in_array($arg['name'], ['key_fingerprint', 'server_salt', 'new_server_salt', 'server_public_key_fingerprints', 'ping_id', 'exchange_id'])) {
                $arg['strlong'] = true;
            }
            if (\in_array($arg['name'], ['peer_tag', 'file_token', 'cdn_key', 'cdn_iv'])) {
                $arg['type'] = 'string';
            }
            if ($x['_'] === 'rpc_result' && $arg['name'] === 'result' && isset($type['connection']->outgoing_messages[$x['req_msg_id']])) {
                /** @var OutgoingMessage */
                $message = $type['connection']->outgoing_messages[$x['req_msg_id']];
                foreach ($this->callbacks[TLCallback::METHOD_BEFORE_CALLBACK][$message->getConstructor()] ?? [] as $callback) {
                    $callback($type['connection']->outgoing_messages[$x['req_msg_id']]->getConstructor());
                }
                if ($message->getType() && \stripos($message->getType(), '<') !== false) {
                    $arg['subtype'] = \str_replace(['Vector<', '>'], '', $message->getType());
                }
            }
            if (isset($type['connection'])) {
                $arg['connection'] = $type['connection'];
            }
            $x[$arg['name']] = $this->deserializeInternal($stream, $promises, $arg);
            if ($arg['name'] === 'random_bytes') {
                if (\strlen($x[$arg['name']]) < 15) {
                    throw new SecurityException(Lang::$current_lang['rand_bytes_too_small']);
                }
                unset($x[$arg['name']]);
            }
        }
        if ($x['_'] === 'dataJSON') {
            return \json_decode($x['data'], true);
        } elseif ($constructorData['type'] === 'JSONValue') {
            switch ($x['_']) {
                case 'jsonNull':
                    return;
                case 'jsonObject':
                    $res = [];
                    foreach ($x['value'] as $pair) {
                        $res[$pair['key']] = $pair['value'];
                    }
                    return $res;
                default:
                    return $x['value'];
            }
        } elseif ($x['_'] === 'photoStrippedSize') {
            $x['inflated'] = new Types\Bytes(Tools::inflateStripped($x['bytes']));
        }
        if (isset($this->callbacks[TLCallback::CONSTRUCTOR_CALLBACK][$x['_']])) {
            foreach ($this->callbacks[TLCallback::CONSTRUCTOR_CALLBACK][$x['_']] as $callback) {
                $promise = Tools::callFork($callback($x));
                if ($promise instanceof Promise) {
                    $promises []= $promise;
                }
            }
        } elseif ($x['_'] === 'rpc_result'
            && isset($type['connection']->outgoing_messages[$x['req_msg_id']])
            && isset($this->callbacks[TLCallback::METHOD_CALLBACK][$type['connection']->outgoing_messages[$x['req_msg_id']]->getConstructor()])) {
            foreach ($this->callbacks[TLCallback::METHOD_CALLBACK][$type['connection']->outgoing_messages[$x['req_msg_id']]->getConstructor()] as $callback) {
                $callback($type['connection']->outgoing_messages[$x['req_msg_id']], $x['result']);
            }
        }
        if ($x['_'] === 'message' && isset($x['reply_markup']['rows'])) {
            foreach ($x['reply_markup']['rows'] as $key => $row) {
                foreach ($row['buttons'] as $bkey => $button) {
                    $x['reply_markup']['rows'][$key]['buttons'][$bkey] = new Types\Button($this->API, $x, $button);
                }
            }
        }
        unset($x['flags'], $x['flags2']);
        return $x;
    }
}
