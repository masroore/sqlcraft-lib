<?php
declare(strict_types=1);
namespace SQLCraft\Metadata;
use SQLCraft\Contracts\Metadata\CheckConstraintInspectorInterface;
use SQLCraft\Contracts\Metadata\ColumnInspectorInterface;
use SQLCraft\Contracts\Metadata\DatabaseInspectorInterface;
use SQLCraft\Contracts\Metadata\ForeignKeyInspectorInterface;
use SQLCraft\Contracts\Metadata\IndexInspectorInterface;
use SQLCraft\Contracts\Metadata\PrivilegeInspectorInterface;
use SQLCraft\Contracts\Metadata\RoutineInspectorInterface;
use SQLCraft\Contracts\Metadata\SequenceInspectorInterface;
use SQLCraft\Contracts\Metadata\ServerInspectorInterface;
use SQLCraft\Contracts\Metadata\TableInspectorInterface;
use SQLCraft\Contracts\Metadata\TriggerInspectorInterface;
use SQLCraft\Contracts\Metadata\UserInspectorInterface;
use SQLCraft\Contracts\Metadata\ViewInspectorInterface;
final readonly class MetadataInspectorSet
{
    public function __construct(
        private ServerInspectorInterface $server, private DatabaseInspectorInterface $database, private TableInspectorInterface $table, private ColumnInspectorInterface $column, private IndexInspectorInterface $index, private ForeignKeyInspectorInterface $foreignKeys, private ViewInspectorInterface $view, private RoutineInspectorInterface $routine, private TriggerInspectorInterface $trigger, private SequenceInspectorInterface $sequence, private CheckConstraintInspectorInterface $checkConstraint, private UserInspectorInterface $user, private ?PrivilegeInspectorInterface $privileges = null) {}
    public function server(): ServerInspectorInterface { return $this->server; }
    public function database(): DatabaseInspectorInterface { return $this->database; }
    public function table(): TableInspectorInterface { return $this->table; }
    public function column(): ColumnInspectorInterface { return $this->column; }
    public function index(): IndexInspectorInterface { return $this->index; }
    public function foreignKeys(): ForeignKeyInspectorInterface { return $this->foreignKeys; }
    public function view(): ViewInspectorInterface { return $this->view; }
    public function routine(): RoutineInspectorInterface { return $this->routine; }
    public function trigger(): TriggerInspectorInterface { return $this->trigger; }
    public function sequence(): SequenceInspectorInterface { return $this->sequence; }
    public function checkConstraint(): CheckConstraintInspectorInterface { return $this->checkConstraint; }
    public function user(): UserInspectorInterface { return $this->user; }
    public function privileges(): ?PrivilegeInspectorInterface { return $this->privileges; }
    public function withServer(ServerInspectorInterface $server): self { return new self($server,$this->database,$this->table,$this->column,$this->index,$this->foreignKeys,$this->view,$this->routine,$this->trigger,$this->sequence,$this->checkConstraint,$this->user,$this->privileges); }
    public function withForeignKeys(ForeignKeyInspectorInterface $foreignKeys): self { return new self($this->server,$this->database,$this->table,$this->column,$this->index,$foreignKeys,$this->view,$this->routine,$this->trigger,$this->sequence,$this->checkConstraint,$this->user,$this->privileges); }
    public function withPrivileges(?PrivilegeInspectorInterface $privileges): self { return new self($this->server,$this->database,$this->table,$this->column,$this->index,$this->foreignKeys,$this->view,$this->routine,$this->trigger,$this->sequence,$this->checkConstraint,$this->user,$privileges); }
}
