<?php

declare(strict_types=1);

namespace OpenSendForm\Admin;

use OpenSendForm\Auth\Csrf;
use OpenSendForm\Form\FormRepository;
use OpenSendForm\Mail\DeliveryService;
use OpenSendForm\Submission\SubmissionRepository;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin submissions screen: a paginated, filterable table (metadata only) plus
 * per-row and bulk retry actions.
 *
 * No submitted content is ever read or displayed here — only delivery
 * metadata. Retry actions drive the existing DeliveryService so the admin and
 * the cron share one code path.
 */
final class SubmissionsController
{
    /** Rows per page. */
    private const PER_PAGE = 50;

    /** Statuses offered in the filter (and the only valid filter values). */
    private const STATUSES = ['received', 'sent', 'failed', 'dead'];

    /**
     * Special filter value exposing the synthetic monitor probes, which are
     * hidden from every other view. Orthogonal to the real delivery statuses:
     * it selects is_synthetic = 1 rows regardless of their delivery status.
     */
    private const SYNTHETIC_FILTER = 'synthetic';

    // --- List -------------------------------------------------------------

    public static function index(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $query = $request->getQueryParams();

        $syntheticView = self::isSyntheticFilter($query['status'] ?? null);
        // In the synthetic view the delivery-status filter does not apply.
        $status = $syntheticView ? null : self::cleanStatus($query['status'] ?? null);
        $formId = self::cleanFormId($query['form'] ?? null);
        $page = max(1, (int) ($query['page'] ?? 1));

        $submissions = self::submissions($c);
        $total = $submissions->countFiltered($status, $formId, $syntheticView);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $offset = ($page - 1) * self::PER_PAGE;

        $rows = $submissions->listPage($status, $formId, self::PER_PAGE, $offset, $syntheticView);

        return AdminView::renderPage($c, $response, 'submissions', [
            'title'    => 'Submissions',
            'rows'     => $rows,
            'forms'    => self::forms($c)->listForms(),
            'statuses' => self::STATUSES,
            'status'   => $syntheticView ? self::SYNTHETIC_FILTER : ($status ?? ''),
            'syntheticView' => $syntheticView,
            'formId'   => $formId,
            'page'     => $page,
            'pages'    => $pages,
            'total'    => $total,
            // "Delete all" is scoped by STATUS only (never the form filter), so
            // its count can differ from $total when a form filter is active.
            // It is disabled entirely in the synthetic view: those probes are
            // auto-purged by the monitor, not deleted by hand here.
            'deleteAllCount' => $syntheticView ? 0 : $submissions->countFiltered($status, null),
        ], 'submissions');
    }

    // --- Retry one --------------------------------------------------------

    public static function retry(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $data = self::formData($request);

        if (!self::csrf($c)->validate($data['_csrf'] ?? null)) {
            self::flash($c)->error('Your session expired. Please try again.');

            return self::redirectBack($response, $data);
        }

        if (!self::hasDelivery($c)) {
            self::flash($c)->error('Mail delivery is not configured, so retries are unavailable.');

            return self::redirectBack($response, $data);
        }

        $id = (int) ($args['id'] ?? 0);
        $submission = self::submissions($c)->findById($id);

        if ($submission === null) {
            self::flash($c)->error('That submission no longer exists.');

            return self::redirectBack($response, $data);
        }

        $status = (string) $submission['status'];
        if (!in_array($status, ['failed', 'dead', 'received'], true)) {
            self::flash($c)->error('Only failed, dead, or unsent (received) submissions can be retried.');

            return self::redirectBack($response, $data);
        }

        $result = self::delivery($c)->attemptDelivery($id);
        self::flash($c)->{$result === DeliveryService::RESULT_SENT ? 'success' : 'error'}(
            self::retryMessage($id, $result)
        );

        return self::redirectBack($response, $data);
    }

    // --- Retry all due ----------------------------------------------------

    public static function retryDue(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $data = self::formData($request);

        if (!self::csrf($c)->validate($data['_csrf'] ?? null)) {
            self::flash($c)->error('Your session expired. Please try again.');

            return self::redirectBack($response, $data);
        }

        if (!self::hasDelivery($c)) {
            self::flash($c)->error('Mail delivery is not configured, so retries are unavailable.');

            return self::redirectBack($response, $data);
        }

        $summary = self::delivery($c)->retryDue();

        if ($summary['attempted'] === 0) {
            self::flash($c)->info('No submissions were due for retry.');
        } else {
            self::flash($c)->success(sprintf(
                'Retried %d due submission(s): %d sent, %d still failed, %d dead.',
                $summary['attempted'],
                $summary[DeliveryService::RESULT_SENT],
                $summary[DeliveryService::RESULT_FAILED],
                $summary[DeliveryService::RESULT_DEAD]
            ));
        }

        return self::redirectBack($response, $data);
    }

    // --- Delete one -------------------------------------------------------

    /**
     * Confirmation step for deleting a single submission: shows its form name,
     * date and status. The current filter is carried through (query params) so
     * cancelling or completing returns to the same view.
     */
    public static function deleteConfirm(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $query = $request->getQueryParams();
        $id = (int) ($args['id'] ?? 0);
        $submission = self::submissions($c)->findById($id);

        if ($submission === null) {
            self::flash($c)->error('That submission no longer exists.');

            return self::redirectBack($response, $query);
        }

        $return = self::filterFrom($query);
        $form = self::forms($c)->findById((int) $submission['form_id']);
        $formName = $form !== null ? (string) $form['name'] : ('#' . (int) $submission['form_id']);

        return AdminView::renderPage($c, $response, 'submission_delete_confirm', [
            'title'         => 'Delete submission',
            'submissionId'  => $id,
            'formName'      => $formName,
            'createdAt'     => (string) $submission['created_at'],
            'status'        => (string) $submission['status'],
            'return'        => $return,
            'backUrl'       => self::listUrl($return),
        ], 'submissions');
    }

    public static function delete(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $data = self::formData($request);

        if (!self::csrf($c)->validate($data['_csrf'] ?? null)) {
            self::flash($c)->error('Your session expired. Please try again.');

            return self::redirectBack($response, $data);
        }

        $id = (int) ($args['id'] ?? 0);
        $deleted = self::submissions($c)->deleteById($id);

        if ($deleted > 0) {
            self::flash($c)->success("Submission #{$id} deleted.");
        } else {
            self::flash($c)->error('That submission no longer exists.');
        }

        return self::redirectBack($response, $data);
    }

    // --- Delete all (respects the current STATUS filter) ------------------

    /**
     * Confirmation step for bulk deletion. Scope is status-only: with a status
     * filter active it targets just that status, otherwise every submission.
     * The count is stated plainly. A zero-count scope bounces back (the list
     * control is disabled in that state, so this is only a forged-link guard).
     */
    public static function deleteAllConfirm(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $query = $request->getQueryParams();
        $status = self::cleanStatus($query['status'] ?? null);
        $count = self::submissions($c)->countFiltered($status, null);

        if ($count === 0) {
            self::flash($c)->info('There are no submissions to delete.');

            return self::redirectBack($response, $query);
        }

        return AdminView::renderPage($c, $response, 'submissions_delete_all_confirm', [
            'title'  => 'Delete all submissions',
            'status' => $status ?? '',
            'count'  => $count,
        ], 'submissions');
    }

    public static function deleteAll(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $data = self::formData($request);

        if (!self::csrf($c)->validate($data['_csrf'] ?? null)) {
            self::flash($c)->error('Your session expired. Please try again.');

            return self::redirectBack($response, $data);
        }

        $status = self::cleanStatus($data['status'] ?? null);
        $deleted = self::submissions($c)->deleteAll($status);

        self::flash($c)->success(sprintf(
            'Deleted %d %ssubmission%s.',
            $deleted,
            $status === null ? '' : $status . ' ',
            $deleted === 1 ? '' : 's'
        ));

        return self::redirectBack($response, $data);
    }

    // --- Helpers ----------------------------------------------------------

    /**
     * Reduce a query/body array to the clean filter triple used for hidden
     * fields and back-links (never echoed raw).
     *
     * @param array<string, mixed> $source
     * @return array{status: string, form: string, page: string}
     */
    private static function filterFrom(array $source): array
    {
        $formId = self::cleanFormId($source['form'] ?? null);

        return [
            'status' => self::viewStatus($source['status'] ?? null),
            'form'   => $formId === null ? '' : (string) $formId,
            'page'   => (string) max(1, (int) ($source['page'] ?? 1)),
        ];
    }

    /**
     * Build a submissions-list URL from a clean filter triple.
     *
     * @param array{status: string, form: string, page: string} $filter
     */
    private static function listUrl(array $filter): string
    {
        $params = [];
        if ($filter['status'] !== '') {
            $params['status'] = $filter['status'];
        }
        if ($filter['form'] !== '') {
            $params['form'] = $filter['form'];
        }
        if ((int) $filter['page'] > 1) {
            $params['page'] = $filter['page'];
        }

        return '/admin/submissions' . ($params === [] ? '' : '?' . http_build_query($params));
    }

    private static function retryMessage(int $id, string $result): string
    {
        return match ($result) {
            DeliveryService::RESULT_SENT    => "Submission #{$id} delivered.",
            DeliveryService::RESULT_FAILED  => "Submission #{$id} still failing; another retry is scheduled.",
            DeliveryService::RESULT_DEAD    => "Submission #{$id} has exhausted its retries and is now dead.",
            DeliveryService::RESULT_SKIPPED => "Submission #{$id} was not attempted — email sending is disabled.",
            default                         => "Submission #{$id} could not be retried.",
        };
    }

    private static function cleanStatus(mixed $status): ?string
    {
        $status = is_string($status) ? $status : '';

        return in_array($status, self::STATUSES, true) ? $status : null;
    }

    private static function isSyntheticFilter(mixed $status): bool
    {
        return $status === self::SYNTHETIC_FILTER;
    }

    /**
     * The status value to carry through hidden fields and back-links so the
     * current view is preserved — a real delivery status, the special
     * 'synthetic' view, or '' for the default list.
     */
    private static function viewStatus(mixed $status): string
    {
        if (self::isSyntheticFilter($status)) {
            return self::SYNTHETIC_FILTER;
        }

        return self::cleanStatus($status) ?? '';
    }

    private static function cleanFormId(mixed $form): ?int
    {
        if (is_string($form) && ctype_digit($form) && (int) $form > 0) {
            return (int) $form;
        }

        return null;
    }

    /**
     * Redirect back to the submissions list, preserving the filter/page the
     * action was invoked from (carried in hidden fields). Values are rebuilt
     * from scratch — never echoed from raw input — so there is no open-redirect
     * surface.
     *
     * @param array<string, mixed> $data
     */
    private static function redirectBack(ResponseInterface $response, array $data): ResponseInterface
    {
        $params = [];
        $status = self::viewStatus($data['status'] ?? null);
        $formId = self::cleanFormId($data['form'] ?? null);
        $page = (int) ($data['page'] ?? 1);

        if ($status !== '') {
            $params['status'] = $status;
        }
        if ($formId !== null) {
            $params['form'] = (string) $formId;
        }
        if ($page > 1) {
            $params['page'] = (string) $page;
        }

        $location = '/admin/submissions';
        if ($params !== []) {
            $location .= '?' . http_build_query($params);
        }

        return $response->withHeader('Location', $location)->withStatus(302);
    }

    private static function submissions(ContainerInterface $c): SubmissionRepository
    {
        /** @var SubmissionRepository $r */
        $r = $c->get(SubmissionRepository::class);

        return $r;
    }

    private static function forms(ContainerInterface $c): FormRepository
    {
        /** @var FormRepository $r */
        $r = $c->get(FormRepository::class);

        return $r;
    }

    private static function hasDelivery(ContainerInterface $c): bool
    {
        return $c->has(DeliveryService::class);
    }

    private static function delivery(ContainerInterface $c): DeliveryService
    {
        /** @var DeliveryService $d */
        $d = $c->get(DeliveryService::class);

        return $d;
    }

    private static function csrf(ContainerInterface $c): Csrf
    {
        /** @var Csrf $s */
        $s = $c->get(Csrf::class);

        return $s;
    }

    private static function flash(ContainerInterface $c): Flash
    {
        /** @var Flash $f */
        $f = $c->get(Flash::class);

        return $f;
    }

    /**
     * @return array<string, mixed>
     */
    private static function formData(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed)) {
            return $parsed;
        }

        parse_str((string) $request->getBody(), $data);

        return $data;
    }
}
