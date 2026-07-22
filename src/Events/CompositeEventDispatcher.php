<?php
declare(strict_types=1);
namespace SQLCraft\Events;
use Psr\EventDispatcher\EventDispatcherInterface;
/** @internal */
final readonly class CompositeEventDispatcher implements EventDispatcherInterface
{ public function __construct(private EventDispatcherInterface $core, private ?EventDispatcherInterface $external=null) {} public function dispatch(object $event): object { $this->core->dispatch($event); return $this->external?->dispatch($event) ?? $event; } }
