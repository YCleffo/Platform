<?php

declare(strict_types=1);

namespace App\Education\Application;

interface CurriculumStore
{
    public function programs(Scope $scope, int $offset): array;
    public function program(Scope $scope, string $id): array;
    public function saveProgram(Scope $scope, ?string $id, array $values): array;
    public function saveModule(Scope $scope, ?string $id, ?string $programId, array $values): array;
    public function module(Scope $scope, string $id): array;
    public function saveCurriculumLesson(Scope $scope, ?string $id, ?string $moduleId, array $values): array;
    public function curriculumLesson(Scope $scope, string $id): array;
    public function groupPrograms(Scope $scope, string $groupId): array;
    public function assignProgram(Scope $scope, string $groupId, string $programId): array;
    public function progress(Scope $scope, ?string $groupId, ?string $studentId): array;
    public function bindLesson(Scope $scope, string $lessonId, ?string $curriculumLessonId): void;
    public function teachers(Scope $scope, string $groupId): array;
    public function setTeachers(Scope $scope, string $groupId, array $accountIds): array;
    public function delegatedScope(Scope $scope, string $type, string $id): Scope;
}
