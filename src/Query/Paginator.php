<?php

declare(strict_types=1);

namespace SQLCraft\Query;

use InvalidArgumentException;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Execution\QueryExecutorInterface;
use SQLCraft\Contracts\Query\TableStatusProviderInterface;

final readonly class Paginator implements \SQLCraft\Contracts\Query\PaginatorInterface
{
    public function __construct(
        private QueryExecutorInterface $executor,
        private SelectQueryRenderer $renderer,
        private ?TableStatusProviderInterface $statusProvider = null,
        private int $maximumLimit = 10000,
    ) {
        if ($maximumLimit < 1) {
            throw new InvalidArgumentException('Maximum pagination limit must be >= 1.');
        }
    }

    #[\Override]
    public function paginate(ConnectionInterface $connection, SelectQuery $query, PaginationParams $params): Page
    {
        if ($params->limit > $this->maximumLimit) {
            throw new InvalidArgumentException(sprintf('Pagination limit cannot exceed %d.', $this->maximumLimit));
        }

        $pageQuery = new SelectQuery($query->table, $query->columns, $query->where, $query->orderBy, $query->groupBy, $query->distinct, $params->limit, $params->offset());
        $renderedPage = $this->renderer->render($pageQuery);
        $rows = $this->executor->query($connection, $renderedPage['sql'], $renderedPage['params'], buffered: true)->fetchAll();
        $approximateRows = $query->where === [] && $this->statusProvider instanceof TableStatusProviderInterface
            ? $this->statusProvider->getApproximateRowCount($connection, $query->table)
            : null;
        if ($approximateRows !== null) {
            return new Page($rows, $params, $approximateRows, true, count($rows) === $params->limit || $params->offset() + count($rows) < $approximateRows);
        }

        $countQuery = new SelectQuery($query->table, $query->columns, $query->where, [], $query->groupBy, false);
        $renderedCount = $this->renderer->render($countQuery);
        $countSql = preg_replace('/^SELECT .*? FROM /i', 'SELECT COUNT(*) FROM ', $renderedCount['sql'], 1);
        if ($countSql === null || $countSql === $renderedCount['sql']) {
            throw new InvalidArgumentException('Unable to construct paginator count query.');
        }
        $countValues = $this->executor->query($connection, $countSql, $renderedCount['params'], buffered: true)->fetchColumn();
        $total = isset($countValues[0]) && is_numeric($countValues[0]) ? (int) $countValues[0] : null;

        return new Page($rows, $params, $total, false, $total === null ? count($rows) === $params->limit : $params->offset() + count($rows) < $total);
    }
}
