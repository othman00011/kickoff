<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/Controller.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__) . '/Models/Tournament.php';

final class HomeController extends Controller
{
    private const DEFAULT_LIMIT = 4;
    private const MAX_LIMIT = 50;

    public function index(): void
    {
        $query = $_GET['q'] ?? '';
        if (!is_string($query)) {
            $this->flash('error', 'Search text must be a single value.');
            $this->redirect('/');
        }

        $query = trim($query);
        if (strlen($query) > 200) {
            $this->flash('error', 'Search text must not exceed 200 bytes.');
            $this->redirect('/');
        }
        if (preg_match('//u', $query) !== 1 || preg_match('/\p{Cc}/u', $query) === 1) {
            $this->flash('error', 'Search text must be valid UTF-8 and must not contain control characters.');
            $this->redirect('/');
        }

        try {
            $page = $this->readPositiveQueryInt($_GET['page'] ?? '1', 'Page', 1);
            $limit = $this->readPositiveQueryInt(
                $_GET['limit'] ?? (string) self::DEFAULT_LIMIT,
                'Page size',
                self::DEFAULT_LIMIT
            );
        } catch (InvalidArgumentException $exception) {
            $this->flash('error', $exception->getMessage());
            $this->redirect('/');
        }

        $limit = min($limit, self::MAX_LIMIT);
        $offset = ($page - 1) * $limit;
        if ($offset > 2_000_000_000) {
            $this->flash('error', 'Requested page is too large.');
            $this->redirect('/');
        }

        $database = Database::getInstance();
        $where = '';
        $parameters = [];
        if ($query !== '') {
            $where = " WHERE name ILIKE :search ESCAPE '\\'";
            $parameters['search'] = '%' . Tournament::escapeLike($query) . '%';
        }

        $totalStatement = $database->query(
            'SELECT COUNT(*) FROM tournaments' . $where,
            $parameters
        );
        $total = (int) $totalStatement->fetchColumn();
        $tournaments = $database->query(
            'SELECT id, name, format, created_at
             FROM tournaments' . $where . '
             ORDER BY created_at DESC, id DESC
             LIMIT :limit OFFSET :offset',
            [
                ...$parameters,
                'limit' => $limit,
                'offset' => $offset,
            ]
        )->fetchAll();

        $this->render('home/index', [
            'tournaments' => $tournaments,
            'query' => $query,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => max(1, (int) ceil($total / $limit)),
            'flashMessage' => $this->consumeFlash(),
        ]);
    }

    private function readPositiveQueryInt(mixed $value, string $label, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/', $value) || (int) $value > 2_000_000_000) {
            throw new InvalidArgumentException(sprintf('%s must be a positive integer.', $label));
        }

        return (int) $value;
    }
}
