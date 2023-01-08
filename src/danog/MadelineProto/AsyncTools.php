<?php

declare(strict_types=1);

/**
 * Tools module.
 *
 * This file is part of MadelineProto.
 * MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU General Public License along with MadelineProto.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2023 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 * @link https://docs.madelineproto.xyz MadelineProto documentation
 */

namespace danog\MadelineProto;

use Amp\DeferredFuture;
use Amp\Future;
use Amp\TimeoutCancellation;
use Amp\TimeoutException;
use Generator;
use Throwable;
use TypeError;

use const LOCK_NB;
use const LOCK_UN;
use function Amp\async;
use function Amp\ByteStream\getOutputBufferStream;
use function Amp\ByteStream\getStdin;
use function Amp\ByteStream\getStdout;
use function Amp\delay;
use function Amp\File\exists;
use function Amp\File\touch as touchAsync;
use function Amp\Future\await;

use function Amp\Future\awaitAny;
use function Amp\Future\awaitFirst;

/**
 * Async tools.
 */
abstract class AsyncTools extends StrTools
{
    /**
     * Synchronously wait for a Future|generator.
     *
     * @deprecated Coroutines are deprecated since amp v3
     * @param Generator|Future $promise The promise to wait for
     */
    public static function wait(Generator|Future $promise)
    {
        if ($promise instanceof Generator) {
            return self::call($promise)->await();
        } elseif (!$promise instanceof Future) {
            return $promise;
        }
        return $promise->await();
    }
    /**
     * Returns a promise that succeeds when all promises succeed, and fails if any promise fails.
     * Returned promise succeeds with an array of values used to succeed each contained promise, with keys corresponding to the array of promises.
     *
     * @deprecated Coroutines are deprecated since amp v3
     * @param array<(Generator|Future)> $promises Promises
     */
    public static function all(array $promises)
    {
        foreach ($promises as &$promise) {
            $promise = self::call($promise);
        }
        return await($promises);
    }
    /**
     * Returns a promise that is resolved when all promises are resolved. The returned promise will not fail.
     *
     * @deprecated Coroutines are deprecated since amp v3
     * @param array<(Future|Generator)> $promises Promises
     */
    public static function any(array $promises)
    {
        foreach ($promises as &$promise) {
            $promise = self::call($promise);
        }
        return awaitAny($promises);
    }
    /**
     * Resolves with a two-item array delineating successful and failed Promise results.
     * The returned promise will only fail if the given number of required promises fail.
     *
     * @deprecated Coroutines are deprecated since amp v3
     * @param array<(Future|Generator)> $promises Promises
     */
    public static function some(array $promises)
    {
        foreach ($promises as &$promise) {
            $promise = self::call($promise);
        }
        return await($promises);
    }
    /**
     * Returns a promise that succeeds when the first promise succeeds, and fails only if all promises fail.
     *
     * @deprecated Coroutines are deprecated since amp v3
     * @param array<(Future|Generator)> $promises Promises
     */
    public static function first(array $promises)
    {
        foreach ($promises as &$promise) {
            $promise = self::call($promise);
        }
        return awaitFirst($promises);
    }
    /**
     * Create an artificial timeout for any \Generator or Promise.
     *
     * @deprecated Coroutines are deprecated since amp v3
     * @param int $timeout In milliseconds
     */
    public static function timeout(Generator|Future $promise, int $timeout): mixed
    {
        return self::call($promise)->await(new TimeoutCancellation($timeout/1000));
    }
    /**
     * Creates an artificial timeout for any `Promise`.
     *
     * If the promise is resolved before the timeout expires, the result is returned
     *
     * If the timeout expires before the promise is resolved, a default value is returned
     *
     * @deprecated Coroutines are deprecated since amp v3
     * @template TReturnAlt
     * @template TReturn
     * @template TGenerator of Generator<mixed, mixed, mixed, TReturn>
     * @param Future<TReturn>|TGenerator $promise Promise to which the timeout is applied.
     * @param int                        $timeout Timeout in milliseconds.
     * @param TReturnAlt                 $default
     * @return TReturn|TReturnAlt
     * @throws TypeError If $promise is not an instance of \Amp\Future, \Generator or \React\Promise\PromiseInterface.
     */
    public static function timeoutWithDefault($promise, int $timeout, $default = null): mixed
    {
        try {
            return self::timeout($promise, $timeout);
        } catch (TimeoutException) {
            return $default;
        }
    }
    /**
     * Convert generator, promise or any other value to a promise.
     *
     * @deprecated Coroutines are deprecated since amp v3
     * @template TReturn
     * @param Generator<mixed, mixed, mixed, TReturn>|Future<TReturn>|TReturn $promise
     * @return Future<TReturn>
     */
    public static function call(mixed $promise): Future
    {
        if ($promise instanceof Generator) {
            $promise = async(function () use ($promise) {
                $yielded = $promise->current();
                do {
                    while (!$yielded instanceof Future) {
                        if (!$promise->valid()) {
                            return $promise->getReturn();
                        }
                        if ($yielded instanceof Generator) {
                            $yielded = self::call($yielded);
                        } else {
                            $yielded = $promise->send($yielded);
                        }
                    }
                    try {
                        $result = $yielded->await();
                    } catch (Throwable $e) {
                        $yielded = $promise->throw($e);
                        continue;
                    }
                    $yielded = $promise->send($result);
                } while (true);
            });
        } elseif (!$promise instanceof Future) {
            $f = new DeferredFuture;
            $f->complete($promise);
            return $f->getFuture();
        }
        return $promise;
    }
    /**
     * Call promise in background.
     *
     * @deprecated Coroutines are deprecated since amp v3
     * @param Generator|Future $promise Promise to resolve
     * @param ?\Generator|Future $actual  Promise to resolve instead of $promise
     * @param string              $file    File
     * @psalm-suppress InvalidScope
     */
    public static function callFork(Generator|Future $promise, $actual = null, string $file = ''): mixed
    {
        if ($actual) {
            $promise = $actual;
        }
        if ($promise instanceof Generator) {
            $promise = self::call($promise);
        }
        return $promise;
    }
    /**
     * Call promise in background, deferring execution.
     *
     * @deprecated Coroutines are deprecated since amp v3
     * @param Generator|Future $promise Promise to resolve
     */
    public static function callForkDefer(Generator|Future $promise): void
    {
        self::callFork($promise);
    }
    /**
     * Call promise $b after promise $a.
     *
     * @deprecated Coroutines are deprecated since amp v3
     * @param Generator|Future $a Promise A
     * @param Generator|Future $b Promise B
     * @psalm-suppress InvalidScope
     */
    public static function after(Generator|Future $a, Generator|Future $b): Future
    {
        return async(function () use ($a, $b) {
            self::call($a)->await();
            return self::call($b)->await();
        });
    }
    /**
     * Asynchronously lock a file
     * Resolves with a callbable that MUST eventually be called in order to release the lock.
     *
     * @param string    $file      File to lock
     * @param integer   $operation Locking mode
     * @param float     $polling   Polling interval
     * @param ?Future   $token     Cancellation token
     * @param ?callable $failureCb Failure callback, called only once if the first locking attempt fails.
     * @return $token is null ? (callable(): void) : ((callable(): void)|null)
     */
    public static function flock(string $file, int $operation, float $polling = 0.1, ?Future $token = null, ?callable $failureCb = null): ?callable
    {
        if (!exists($file)) {
            touchAsync($file);
        }
        $operation |= LOCK_NB;
        $res = \fopen($file, 'c');
        do {
            $result = \flock($res, $operation);
            if (!$result) {
                if ($failureCb) {
                    $failureCb();
                    $failureCb = null;
                }
                if ($token) {
                    if (self::timeoutWithDefault($token, (int) ($polling*1000), false)) {
                        return null;
                    }
                } else {
                    delay($polling);
                }
            }
        } while (!$result);
        return static function () use (&$res): void {
            if ($res) {
                \flock($res, LOCK_UN);
                \fclose($res);
                $res = null;
            }
        };
    }
    /**
     * Asynchronously sleep.
     *
     * @param float $time Number of seconds to sleep for
     */
    public static function sleep(float $time): void
    {
        delay($time);
    }
    /**
     * Asynchronously read line.
     *
     * @param string $prompt Prompt
     */
    public static function readLine(string $prompt = ''): string
    {
        try {
            Magic::togglePeriodicLogging();
            $stdin = getStdin();
            $stdout = getStdout();
            if ($prompt) {
                $stdout->write($prompt);
            }
            static $lines = [''];
            while (\count($lines) < 2 && ($chunk = $stdin->read()) !== null) {
                $chunk = \explode("\n", \str_replace(["\r", "\n\n"], "\n", $chunk));
                $lines[\count($lines) - 1] .= \array_shift($chunk);
                $lines = \array_merge($lines, $chunk);
            }
        } finally {
            Magic::togglePeriodicLogging();
        }
        return \array_shift($lines);
    }
    /**
     * Asynchronously write to stdout/browser.
     *
     * @param string $string Message to echo
     */
    public static function echo(string $string): void
    {
        getOutputBufferStream()->write($string);
    }
}
