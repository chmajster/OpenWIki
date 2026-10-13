<?php

declare (strict_types=1);
namespace OpenWiki\Wiki;

use OpenWiki\Core\Database;
use OpenWiki\Core\Request;
/** UI and REST adapters share context validation and domain services. */
final class CollaborationActions
{
    public function __construct(private readonly Database $db)
    {
    }
    public function run(string $action, int $page, ?int $user, array $input, ?array $ceiling, Request $request): array
    {
        $context = (new CollaborationAccess($this->db))->context($page, $user, $ceiling);
        foreach (['body', 'q', 'status'] as $field) {
            if (isset($input[$field]) && !is_string($input[$field])) {
                throw new \InvalidArgumentException('Pole ' . $field . ' musi być tekstem.');
            }
        }
        $id = isset($input['id']) ? CollaborationAccess::positive($input['id']) : null;
        $comments = new CommentService($this->db);
        $tasks = new PageTaskService($this->db);
        if ($user === null && $action !== 'discussions') {
            throw new \DomainException('Zaloguj się, aby pracować nad dokumentem.');
        }
        switch ($action) {
            case 'discussions':
                return $comments->threads($page, CollaborationAccess::positive($input['revision_number'] ?? null), $user, $ceiling, (string) ($input['status'] ?? 'all'), (int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 25), CollaborationAccess::positive($input['thread_id'] ?? null, true));
            case 'comment':
                return ['data' => $comments->read($id ?? throw new \InvalidArgumentException('Podaj komentarz.'), $user, $ceiling, $page)];
            case 'discussion-replies':
                return $comments->replies($id ?? 0, $user, $ceiling, $page, (int) ($input['page'] ?? 1));
            case 'comment-create':
                $id = $comments->create($page, (int) $context['space']['id'], $user, (string) ($input['body'] ?? ''), CollaborationAccess::positive($input['parent_id'] ?? null, true), '', '', $input, $ceiling, $request);
                return ['data' => $comments->read($id, $user, $ceiling, $page)];
            case 'comment-update':
                if (!$comments->update($id ?? 0, $user, (string) ($input['body'] ?? ''), $page, $ceiling, $input, $request)) {
                    throw new \OutOfBoundsException('Nie znaleziono komentarza.');
                }
                return ['data' => $comments->read($id, $user, $ceiling, $page)];
            case 'comment-delete':
                if (!$comments->delete($id ?? 0, $user, false, $page, $ceiling, $request)) {
                    throw new \OutOfBoundsException('Nie znaleziono komentarza.');
                }
                return ['data' => ['deleted' => true]];
            case 'discussion-status':
                $comments->read($id ?? 0, $user, $ceiling, $page);
                return ['data' => $comments->resolve($id, $user, $input, $ceiling, $request)];
            case 'tasks':
                return $tasks->forPage($page, $user, $ceiling, (int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 20));
            case 'task':
                return ['data' => $tasks->read($id ?? 0, $user, $ceiling, $page)];
            case 'task-create':
                return ['data' => $tasks->create($page, $user, $input, $ceiling, $request)];
            case 'task-update':
                return ['data' => $tasks->update($id ?? 0, $user, $input, $ceiling, $page, $request)];
            case 'task-history':
                $tasks->read($id ?? 0, $user, $ceiling, $page);
                return $tasks->historyFor($id, $user, $ceiling, (int) ($input['page'] ?? 1));
            case 'assignees':
                $revision = CollaborationAccess::positive($input['revision_number'] ?? null, true);
                if (isset($input['thread_id'])) {
                    $thread = $comments->read(CollaborationAccess::positive($input['thread_id']), $user, $ceiling, $page);
                    $revision = (int) $thread['revision_number'];
                }
                $data = $tasks->assignees($page, $user, $revision, $ceiling, (string) ($input['q'] ?? ''));
                if (($thread['visibility'] ?? '') === 'working') {
                    $workingContext = (new CollaborationAccess($this->db))->context($page, $user, $ceiling, $revision, true);
                    $data = (new CollaborationAudience($this->db))->eligible($workingContext, $revision, true, (string) ($input['q'] ?? ''));
                }
                return ['data' => $data];
            default:
                throw new \OutOfBoundsException('Nie znaleziono operacji.');
        }
    }
}
