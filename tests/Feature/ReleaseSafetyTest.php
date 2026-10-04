<?php

declare(strict_types=1);

test('preview releases require successful same repository push CI and check out the tested commit', function (): void {
    $workflow = file_get_contents(base_path('.github/workflows/auto-release.yml'));

    expect($workflow)->toContain(
        'workflow_run:',
        'workflows: [CI]',
        "github.event.workflow_run.conclusion == 'success'",
        "github.event.workflow_run.event == 'push'",
        'github.event.workflow_run.head_repository.full_name == github.repository',
        "workflow.data.path !== '.github/workflows/ci.yml'",
        'branch.data.commit.sha !== run.head_sha',
        'ref: ${{ github.event.workflow_run.head_sha }}',
        'target_commitish: ${{ github.event.workflow_run.head_sha }}',
        'persist-credentials: false',
        'prerelease: true',
        'make_latest: false',
    )->not->toContain('graphite_token', 'withgraphite/', ':latest', 'value=latest', 'prerelease: false');
});

test('CI validates without modifying source or receiving write permissions', function (): void {
    $workflow = file_get_contents(base_path('.github/workflows/ci.yml'));

    expect($workflow)->toContain('contents: read', 'composer test:lint', 'rector process --dry-run', 'npm run types:check')
        ->not->toContain('contents: write', 'git-auto-commit-action', 'Commit Pint Changes', 'Commit Rector Changes');
});

test('manual official release requires exact commit CI and immutable version tags', function (): void {
    $workflow = file_get_contents(base_path('.github/workflows/manual-official-release.yml'));

    expect($workflow)->toContain(
        "if: github.ref == 'refs/heads/main'",
        'environment: release',
        "workflow_id: 'ci.yml'",
        'head_sha: context.sha',
        "event: 'push'",
        "run.conclusion !== 'success'",
        'candidate.head_sha === context.sha',
        'branch.data.commit.sha !== context.sha',
        'immutable versions cannot be overwritten',
        'ref: ${{ needs.prepare.outputs.commit }}',
        'target_commitish: ${{ needs.prepare.outputs.commit }}',
        'Drafts and prereleases cannot update stable latest.',
        'persist-credentials: false',
    )->not->toContain('update-version:', 'git pull', 'git push', 'git-auto-commit-action', 'fail_if_tag_exists');
});

test('ad hoc Docker build cannot bypass release guards to publish stable images', function (): void {
    $workflow = file_get_contents(base_path('.github/workflows/docker-latest.yml'));

    expect($workflow)->toContain('push: false', 'contents: read')
        ->not->toContain('packages: write', 'docker/login-action', ':latest', '--push');
});

test('source release archives retain operator documentation', function (): void {
    $attributes = file_get_contents(base_path('.gitattributes'));

    expect($attributes)->not->toContain('README.md export-ignore', 'CHANGELOG.md export-ignore');
});

test('workflow credentials are provided by GitHub rather than literal tokens', function (): void {
    foreach (glob(base_path('.github/workflows/*.yml')) as $file) {
        $workflow = file_get_contents($file);
        preg_match_all('/^\s*(?:password|token|[a-z_]+_token):\s*(.+)$/m', $workflow, $matches);

        foreach ($matches[1] as $value) {
            expect(trim($value))->toStartWith('${{ secrets.');
        }
    }
});
