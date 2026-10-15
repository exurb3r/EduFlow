import { usePage } from '@inertiajs/react';

/**
 * Whether the signed-in user is a student.
 *
 * Only students have an academic record and a student-only route, so
 * navigation that only makes sense for them (the profile entry point) needs to
 * know. Read from the server rather than inferred from the URL so it stays
 * correct across role changes and impersonation.
 */
export function useIsStudent(): boolean {
    return usePage().props.auth.isStudent === true;
}
