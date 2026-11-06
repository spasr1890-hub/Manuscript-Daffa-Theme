<?php

/**
 * @file plugins/themes/manuscriptDaffa/classes/FileSizes.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class FileSizes
 *
 * @brief Sizes of the files of one submission for the back end file lists.
 *
 *  The sizes are read from the files directory with one query
 *  (submission_files + files). Nothing is downloaded or logged as a download.
 *
 *  Who gets what:
 *  - journal managers, site admins and editors/assistants assigned to the
 *    submission: every file of the submission;
 *  - authors assigned to the submission: the files authors see in their
 *    dashboard (submission, revisions, copyedited, proofs, review attachments
 *    released to the author) and their own uploads;
 *  - reviewers: only the files of their own review assignment (the files
 *    given to them and their own review attachments);
 *  - everybody else: nothing.
 */

namespace APP\plugins\themes\manuscriptDaffa\classes;

use APP\core\Application;
use APP\facades\Repo;
use Illuminate\Support\Facades\DB;
use PKP\security\Role;
use PKP\submissionFile\SubmissionFile;
use Throwable;

class FileSizes
{
    /** File stages an assigned author can see in the author dashboard */
    public const AUTHOR_FILE_STAGES = [
        SubmissionFile::SUBMISSION_FILE_SUBMISSION,
        SubmissionFile::SUBMISSION_FILE_REVIEW_REVISION,
        SubmissionFile::SUBMISSION_FILE_COPYEDIT,
        SubmissionFile::SUBMISSION_FILE_PROOF,
    ];

    /**
     * @return array<int,int>|null submission file id => size in bytes, or
     *  null when the user may not see the submission at all.
     */
    public static function forSubmission(int $submissionId): ?array
    {
        $request = Application::get()->getRequest();
        $user = $request->getUser();
        $context = $request->getContext();
        if (!$user || !$context || $submissionId <= 0) {
            return null;
        }

        $submission = Repo::submission()->get($submissionId);
        if (!$submission || (int) $submission->getData('contextId') !== (int) $context->getId()) {
            return null;
        }

        $userId = (int) $user->getId();
        $all = $user->hasRole([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN], (int) $context->getId())
            || $user->hasRole([Role::ROLE_ID_SITE_ADMIN], Application::SITE_CONTEXT_ID);

        $assignedRoles = DB::table('stage_assignments as sa')
            ->join('user_groups as ug', 'ug.user_group_id', '=', 'sa.user_group_id')
            ->where('sa.submission_id', $submissionId)
            ->where('sa.user_id', $userId)
            ->pluck('ug.role_id')
            ->map(fn ($r) => (int) $r)
            ->all();

        if (array_intersect($assignedRoles, [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT])) {
            $all = true;
        }
        $isAuthor = in_array(Role::ROLE_ID_AUTHOR, $assignedRoles, true);

        $reviewIds = DB::table('review_assignments')
            ->where('submission_id', $submissionId)
            ->where('reviewer_id', $userId)
            ->where('declined', 0)
            ->where('cancelled', 0)
            ->pluck('review_id')
            ->map(fn ($r) => (int) $r)
            ->all();

        if (!$all && !$isAuthor && !$reviewIds) {
            return null;
        }

        $reviewFileIds = [];
        if (!$all && $reviewIds) {
            $reviewFileIds = DB::table('review_files')
                ->whereIn('review_id', $reviewIds)
                ->pluck('submission_file_id')
                ->map(fn ($r) => (int) $r)
                ->all();
        }

        $rows = DB::table('submission_files as sf')
            ->join('files as f', 'f.file_id', '=', 'sf.file_id')
            ->where('sf.submission_id', $submissionId)
            ->select(['sf.submission_file_id', 'sf.file_stage', 'sf.assoc_type', 'sf.assoc_id', 'sf.uploader_user_id', 'sf.viewable', 'f.path'])
            ->get();

        $fs = null;
        try {
            $fs = app()->get('file')->fs;
        } catch (Throwable $e) {
            $fs = null;
        }
        $filesDir = rtrim((string) \PKP\config\Config::getVar('files', 'files_dir'), '/');

        $sizes = [];
        foreach ($rows as $row) {
            $id = (int) $row->submission_file_id;
            if (!$all && !self::allowed($row, $userId, $isAuthor, $reviewIds, $reviewFileIds)) {
                continue;
            }
            $size = null;
            try {
                if ($fs) {
                    $size = $fs->fileSize($row->path);
                }
            } catch (Throwable $e) {
                $size = null;
            }
            if ($size === null && $filesDir !== '') {
                $path = $filesDir . '/' . ltrim($row->path, '/');
                if (!str_contains($row->path, '..') && is_file($path)) {
                    $size = @filesize($path);
                }
            }
            if (is_int($size) && $size >= 0) {
                $sizes[$id] = $size;
            }
        }

        return $sizes;
    }

    /**
     * May an author or reviewer see the size of this file?
     */
    protected static function allowed(object $row, int $userId, bool $isAuthor, array $reviewIds, array $reviewFileIds): bool
    {
        $id = (int) $row->submission_file_id;
        $stage = (int) $row->file_stage;

        if ($reviewIds) {
            if (in_array($id, $reviewFileIds, true)) {
                return true;
            }
            if ($stage === SubmissionFile::SUBMISSION_FILE_REVIEW_ATTACHMENT
                && (int) $row->assoc_type === Application::ASSOC_TYPE_REVIEW_ASSIGNMENT
                && in_array((int) $row->assoc_id, $reviewIds, true)) {
                return true;
            }
        }

        if ($isAuthor) {
            if ((int) $row->uploader_user_id === $userId) {
                return true;
            }
            if (in_array($stage, self::AUTHOR_FILE_STAGES, true)) {
                return true;
            }
            if ($stage === SubmissionFile::SUBMISSION_FILE_REVIEW_ATTACHMENT && (int) $row->viewable === 1) {
                return true;
            }
        }

        return false;
    }
}
