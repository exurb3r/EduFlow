import { Head, router, useHttp, usePage, usePoll } from '@inertiajs/react';
import {
    ArrowLeft,
    BookOpenText,
    CheckCheck,
    ChevronRight,
    FileText,
    FlaskConical,
    History,
    ListFilter,
    Plus,
    Save,
    ShieldCheck,
    SlidersHorizontal,
    Sparkles,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/reui/alert';
import { Badge } from '@/components/reui/badge';
import {
    Frame,
    FrameDescription,
    FrameHeader,
    FramePanel,
    FrameTitle,
} from '@/components/reui/frame';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
    DialogFooter,
} from '@/components/ui/dialog';
import { assess, publish, store } from '@/routes/finance/policies';
import { importMethod as importDocument } from '@/actions/App/Http/Controllers/InstitutionPolicyController';

type Clause = { id: string; title: string; text: string };
type Category = {
    id: string;
    name: string;
    description: string;
    inclusions: string;
    exclusions: string;
    clause_ids: string[];
};
type Example = { description: string; category_id: string };
type Controls = {
    minimum_reserve: string;
    max_auto_payment: string;
    max_daily_disbursement: string;
    max_fee: string;
};
type Content = {
    title: string;
    clauses: Clause[];
    categories: Category[];
    examples: Example[];
    controls: Controls;
    priorities: string;
    clarifications: string[];
    predecessor_id?: number | null;
};
type Version = {
    id: number;
    title: string;
    created_by: number;
    content: Content;
    content_digest: string;
    created_at: string;
};
type Judgment = {
    category_id: string | null;
    explanation: string;
    questions: string[];
    missing_evidence: string[];
    citations: { clause_id: string; quote: string }[];
    priority: string;
    needs_review: boolean;
    can_execute: false;
};
type Assessment = {
    id: number;
    institution_policy_version_id: number;
    kind: string;
    state: string;
    result:
        | (Partial<Judgment> & {
              all_passed?: boolean;
              examples?: {
                  index: number;
                  expected: string;
                  actual: string;
                  passed: boolean;
                  assessment: Judgment;
              }[];
          })
        | null;
    last_error: string | null;
    provider: string | null;
    model: string | null;
    policy_digest: string;
    input: {
        workflow_run_id?: number;
        invoice_version_id?: number;
        description?: string;
    };
};
type Props = {
    institution: { id: number; name: string };
    versions: Version[];
    current: {
        institution_policy_version_id: number;
        publication_digest: string;
        approved_by: number;
        controls_active: boolean;
    } | null;
    assessments: Assessment[];
    planAdvice: Assessment[];
    canPublish: boolean;
    aiEnabled: boolean;
    financeUrl: string;
    queueReady: boolean;
};

const sections = [
    { id: 'policy', label: 'Policy document', icon: BookOpenText },
    { id: 'categories', label: 'Categories', icon: ListFilter },
    { id: 'examples', label: 'Examples', icon: FileText },
    { id: 'controls', label: 'Financial controls', icon: SlidersHorizontal },
    { id: 'playground', label: 'Playground', icon: FlaskConical },
    { id: 'publication', label: 'Review & publish', icon: ShieldCheck },
] as const;
type Section = (typeof sections)[number]['id'];

const controlLabels: Record<keyof Controls, string> = {
    minimum_reserve: 'Protected reserve',
    max_auto_payment: 'Per automatic payment',
    max_daily_disbursement: 'Daily disbursement ceiling',
    max_fee: 'Maximum fee',
};

function blank(): Content {
    return {
        title: '',
        clauses: [{ id: 'policy-1', title: '', text: '' }],
        categories: [],
        examples: [],
        priorities: '',
        clarifications: [],
        controls: {
            minimum_reserve: '0',
            max_auto_payment: '0',
            max_daily_disbursement: '0',
            max_fee: '0',
        },
    };
}

function Field({
    label,
    htmlFor,
    hint,
    children,
}: {
    label: string;
    htmlFor: string;
    hint?: string;
    children: ReactNode;
}) {
    return (
        <div className="flex min-w-0 flex-col gap-2">
            <Label htmlFor={htmlFor}>{label}</Label>
            {children}
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
        </div>
    );
}

function Panel({
    title,
    description,
    children,
    action,
}: {
    title: string;
    description: string;
    children: ReactNode;
    action?: ReactNode;
}) {
    return (
        <Frame spacing="default" stacked>
            <FrameHeader>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <FrameTitle>{title}</FrameTitle>
                        <FrameDescription>{description}</FrameDescription>
                    </div>
                    {action}
                </div>
            </FrameHeader>
            <FramePanel>
                <div className="flex min-w-0 flex-col gap-6">{children}</div>
            </FramePanel>
        </Frame>
    );
}

function JudgmentView({ item }: { item: Assessment }) {
    if (item.state === 'queued' || item.state === 'running')
        return (
            <div role="status" className="flex flex-col gap-3">
                <p className="text-sm">
                    Assessment {item.state}. Background worker required.
                </p>
                <Skeleton className="h-5 w-2/3" />
                <Skeleton className="h-16 w-full" />
            </div>
        );
    if (!item.result)
        return (
            <Alert variant="warning">
                <AlertTitle>Needs review</AlertTitle>
                <AlertDescription>
                    {item.last_error ?? 'No interpretation recorded.'}
                </AlertDescription>
            </Alert>
        );
    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center gap-2">
                <Badge
                    variant={
                        item.result.needs_review ||
                        (item.kind === 'examples' && !item.result.all_passed)
                            ? 'warning-light'
                            : 'success-light'
                    }
                >
                    {item.kind === 'examples'
                        ? item.result.all_passed
                            ? 'Examples passed'
                            : 'Example mismatches'
                        : (item.result.category_id ??
                          (item.kind === 'authoring'
                              ? 'Policy interpretation'
                              : 'Needs review'))}
                </Badge>
                <Badge variant="outline">Advisory only</Badge>
            </div>
            {item.result.explanation && (
                <p className="text-sm">{item.result.explanation}</p>
            )}
            {item.result.citations?.map((citation, index) => (
                <blockquote
                    key={index}
                    className="flex flex-col gap-1 border-l-2 border-border pl-4 text-sm"
                >
                    <span className="text-xs font-medium text-muted-foreground">
                        {citation.clause_id}
                    </span>
                    <span>“{citation.quote}”</span>
                </blockquote>
            ))}
            {(item.result.questions?.length ?? 0) > 0 && (
                <Alert variant="warning">
                    <AlertTitle>Clarify before publishing</AlertTitle>
                    <AlertDescription>
                        <ul className="list-inside list-disc">
                            {item.result.questions?.map((question) => (
                                <li key={question}>{question}</li>
                            ))}
                        </ul>
                    </AlertDescription>
                </Alert>
            )}
            {(item.result.missing_evidence?.length ?? 0) > 0 && (
                <p className="text-sm">
                    Missing evidence: {item.result.missing_evidence?.join('; ')}
                </p>
            )}
            {item.result.examples?.map((example) => (
                <div
                    key={example.index}
                    className="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3 text-sm"
                >
                    <span>
                        Example {example.index + 1}: expected {example.expected}
                        , got {example.actual}
                    </span>
                    <Badge
                        variant={
                            example.passed ? 'success-light' : 'warning-light'
                        }
                    >
                        {example.passed ? 'Match' : 'Review'}
                    </Badge>
                </div>
            ))}
            <p className="text-xs text-muted-foreground">
                Assessment #{item.id} ·{' '}
                {item.provider ?? 'Provider not recorded'} ·{' '}
                {item.model ?? 'Model not recorded'}. No payment authority.
            </p>
        </div>
    );
}

export default function InstitutionPolicies({
    institution,
    versions,
    current,
    assessments,
    planAdvice,
    canPublish,
    aiEnabled,
    financeUrl,
    queueReady,
}: Props) {
    const user = usePage<{ auth: { user: { id: number } } }>().props.auth.user;
    const [selected, setSelected] = useState<Version | null>(
        versions[0] ?? null,
    );
    const [content, setContent] = useState<Content>(
        versions[0]?.content ?? blank(),
    );
    const [section, setSection] = useState<Section>('policy');
    const [dirty, setDirty] = useState(false);
    const [notice, setNotice] = useState('');
    const [noticeTitle, setNoticeTitle] = useState('Saved');
    const [description, setDescription] = useState('');
    const [reason, setReason] = useState('');
    const [attested, setAttested] = useState(false);
    const [pendingVersion, setPendingVersion] = useState<string | null>(null);
    const [localAssessment, setLocalAssessment] = useState<Assessment | null>(
        null,
    );
    const saveHttp = useHttp<Record<string, never>, { data: Version }>({});
    const assessmentHttp = useHttp<Record<string, never>, { data: Assessment }>(
        {},
    );
    const publishHttp = useHttp<Record<string, never>, { data: unknown }>({});
    const documentHttp = useHttp<
        { document: File | null },
        { data: { text: string; name: string; digest: string } }
    >({ document: null });
    usePoll(3000, {
        only: ['assessments', 'current', 'versions', 'planAdvice'],
    });
    const latest = (kind: string) =>
        assessments.find(
            (item) =>
                item.institution_policy_version_id === selected?.id &&
                item.kind === kind,
        ) ?? (localAssessment?.kind === kind ? localAssessment : null);
    const edit = <K extends keyof Content>(key: K, value: Content[K]) => {
        setContent((old) => ({ ...old, [key]: value }));
        setDirty(true);
        setNotice('');
    };
    const canAssess = !!selected && !dirty && queueReady;
    const activeVersion = versions.find(
        (version) => version.id === current?.institution_policy_version_id,
    );
    const comparison =
        activeVersion?.id !== selected?.id
            ? activeVersion
            : versions.find(
                  (version) => version.id === selected?.content.predecessor_id,
              );
    const changedClauses = content.clauses.filter(
        (clause) =>
            comparison?.content.clauses.find((item) => item.id === clause.id)
                ?.text !== clause.text,
    );
    const errors = [
        ...Object.values(saveHttp.errors),
        ...Object.values(assessmentHttp.errors),
        ...Object.values(publishHttp.errors),
        ...Object.values(documentHttp.errors),
    ]
        .flat()
        .filter(Boolean);

    async function importPolicy() {
        try {
            const response = await documentHttp.post(importDocument.url());
            edit('clauses', [
                ...content.clauses.filter((clause) => clause.text.trim()),
                ...(response.data.text.match(/[\s\S]{1,3500}/g) ?? []).map(
                    (text, index) => ({
                        id: `source-${content.clauses.length + index + 1}`,
                        title: response.data.name,
                        text,
                    }),
                ),
            ]);
            setNotice(
                'Document text imported locally. Review extracted wording and split long text into cited clauses before saving.',
            );
        } catch {
            /* Uploaded document errors stay inline. */
        }
    }

    async function saveRevision() {
        saveHttp.transform(() => ({
            ...content,
            request_key: crypto.randomUUID(),
            predecessor_id: selected?.id ?? null,
            expected_current_digest: current?.publication_digest ?? null,
        }));
        try {
            const response = await saveHttp.post(store.url());
            setSelected(response.data);
            setContent(response.data.content);
            setDirty(false);
            setNoticeTitle('Saved');
            setNotice(
                'Revision saved. Interpret policy and test examples before independent publication.',
            );
            router.reload({ only: ['versions'] });
        } catch {
            /* Field errors remain visible. */
        }
    }
    async function run(kind: string) {
        if (!selected || dirty) return;
        assessmentHttp.transform(() => ({
            kind,
            request_key: crypto.randomUUID(),
            expected_digest: selected.content_digest,
            description,
        }));
        try {
            const response = await assessmentHttp.post(assess.url(selected.id));
            setLocalAssessment(response.data);
            router.reload({ only: ['assessments'] });
        } catch {
            /* Inline errors own failed request. */
        }
    }
    async function publishRevision() {
        if (!selected || dirty) return;
        publishHttp.transform(() => ({
            expected_digest: selected.content_digest,
            expected_current_digest: current?.publication_digest ?? null,
            reason,
            attestation: attested,
        }));
        try {
            await publishHttp.post(publish.url(selected.id));
            setNoticeTitle('Published');
            setNotice(
                'Policy published by independent reviewer. No payment approved.',
            );
            router.reload({ only: ['versions', 'current'] });
        } catch {
            /* Inline errors own failed request. */
        }
    }
    function chooseVersion(value: string) {
        if (dirty) {
            setPendingVersion(value);
            return;
        }
        openVersion(value);
    }
    function openVersion(value: string) {
        if (value === 'new') {
            setContent(blank());
            setSelected(null);
            setDirty(false);
            return;
        }
        const version = versions.find((item) => String(item.id) === value);
        if (version) {
            setSelected(version);
            setContent(version.content);
            setDirty(false);
            setLocalAssessment(null);
        }
    }

    return (
        <>
            <Head title="Institution policies" />
            <div className="min-h-screen bg-background text-foreground">
                <a
                    href="#policy-workspace"
                    className="sr-only focus:not-sr-only"
                >
                    Skip to policy workspace
                </a>
                <header className="border-b border-border">
                    <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-4 py-4 sm:px-6">
                        <div className="flex items-center gap-3">
                            <Button variant="ghost" size="sm" asChild>
                                <a href={financeUrl}>
                                    <ArrowLeft />
                                    Finance
                                </a>
                            </Button>
                            <span className="text-sm font-medium">
                                {institution.name}
                            </span>
                            <Badge variant="outline">Policy workspace</Badge>
                        </div>
                        <div className="flex items-center gap-2">
                            <Badge
                                variant={
                                    current?.controls_active
                                        ? 'success-light'
                                        : 'warning-light'
                                }
                            >
                                {current
                                    ? current.controls_active
                                        ? 'Published policy'
                                        : 'Published policy · controls superseded'
                                    : 'No active policy'}
                            </Badge>
                            <Button
                                variant="outline"
                                size="sm"
                                type="button"
                                onClick={() => chooseVersion('new')}
                            >
                                <Plus />
                                New policy
                            </Button>
                        </div>
                    </div>
                </header>
                <main
                    id="policy-workspace"
                    className="mx-auto flex max-w-7xl flex-col gap-6 px-4 py-6 sm:px-6"
                >
                    <div className="flex flex-wrap items-end justify-between gap-4">
                        <div className="flex flex-col gap-1">
                            <p className="text-xs font-medium text-muted-foreground">
                                INSTITUTION GOVERNANCE
                            </p>
                            <h1 className="text-2xl font-semibold tracking-tight">
                                Your policy. Clear decisions.
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                Write how your institution works. Test what the
                                agent understands.
                            </p>
                        </div>
                        <Button
                            type="button"
                            size="sm"
                            disabled={saveHttp.processing}
                            onClick={saveRevision}
                        >
                            <Save />
                            {saveHttp.processing ? 'Saving…' : 'Save revision'}
                        </Button>
                    </div>
                    {notice && (
                        <Alert role="status" variant="success">
                            <CheckCheck />
                            <AlertTitle>{noticeTitle}</AlertTitle>
                            <AlertDescription>{notice}</AlertDescription>
                        </Alert>
                    )}
                    {errors.length > 0 && (
                        <Alert variant="destructive">
                            <AlertTitle>Check these details</AlertTitle>
                            <AlertDescription>
                                <ul className="list-inside list-disc">
                                    {errors.map((error, index) => (
                                        <li key={index}>{String(error)}</li>
                                    ))}
                                </ul>
                            </AlertDescription>
                        </Alert>
                    )}
                    <div className="grid min-w-0 gap-6 lg:grid-cols-[220px_minmax(0,1fr)]">
                        <aside className="flex min-w-0 flex-col gap-4">
                            <Field htmlFor="version" label="Saved revision">
                                <Select
                                    value={selected ? String(selected.id) : ''}
                                    onValueChange={chooseVersion}
                                >
                                    <SelectTrigger
                                        id="version"
                                        className="w-full min-w-0 [&_[data-slot=select-value]]:truncate"
                                    >
                                        <SelectValue placeholder="New policy" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {versions.map((version) => (
                                            <SelectItem
                                                key={version.id}
                                                value={String(version.id)}
                                            >
                                                Revision #{version.id}:{' '}
                                                {version.title}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                            <nav
                                aria-label="Policy sections"
                                className="flex gap-1 overflow-x-auto lg:flex-col"
                            >
                                {sections.map((item) => (
                                    <Button
                                        key={item.id}
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        aria-current={
                                            section === item.id
                                                ? 'page'
                                                : undefined
                                        }
                                        className={`justify-start ${section === item.id ? 'bg-muted' : ''}`}
                                        onClick={() => setSection(item.id)}
                                    >
                                        <item.icon />
                                        {item.label}
                                        <ChevronRight className="ml-auto" />
                                    </Button>
                                ))}
                            </nav>
                            <p className="text-xs text-muted-foreground">
                                {dirty
                                    ? 'Unsaved changes. Save before testing.'
                                    : selected
                                      ? `Revision #${selected.id}. Saved evidence is immutable.`
                                      : 'Start with your policy text. No code required.'}
                            </p>
                            <Alert>
                                <ShieldCheck />
                                <AlertTitle>Separate authority</AlertTitle>
                                <AlertDescription>
                                    AI suggests. Institution approves.
                                    Publishing never sends money.
                                </AlertDescription>
                            </Alert>
                        </aside>
                        <div className="flex min-w-0 flex-col gap-6">
                            {section === 'policy' && (
                                <Panel
                                    title="Policy document"
                                    description="Your institution’s words, with stable clause references."
                                    action={
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                edit('clauses', [
                                                    ...content.clauses,
                                                    {
                                                        id: `policy-${content.clauses.length + 1}`,
                                                        title: '',
                                                        text: '',
                                                    },
                                                ])
                                            }
                                        >
                                            <Plus />
                                            Add clause
                                        </Button>
                                    }
                                >
                                    <Field htmlFor="title" label="Policy title">
                                        <Input
                                            id="title"
                                            value={content.title}
                                            onChange={(event) =>
                                                edit(
                                                    'title',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="Institution finance and transaction policy"
                                        />
                                    </Field>
                                    <div className="flex flex-wrap items-end gap-3">
                                        <Field
                                            htmlFor="policy-document"
                                            label="Import document"
                                            hint="Text, Markdown or text-based PDF. Extracted locally; review before saving."
                                        >
                                            <Input
                                                id="policy-document"
                                                type="file"
                                                accept=".txt,.md,.pdf"
                                                onChange={(event) =>
                                                    documentHttp.setData(
                                                        'document',
                                                        event.target
                                                            .files?.[0] ?? null,
                                                    )
                                                }
                                            />
                                        </Field>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            disabled={
                                                !documentHttp.data.document ||
                                                documentHttp.processing
                                            }
                                            onClick={importPolicy}
                                        >
                                            {documentHttp.processing
                                                ? 'Extracting…'
                                                : 'Import text'}
                                        </Button>
                                    </div>
                                    {content.clauses.map((clause, index) => (
                                        <div
                                            key={index}
                                            className="flex flex-col gap-4 border-b border-border pb-6"
                                        >
                                            <div className="grid gap-4 sm:grid-cols-[160px_minmax(0,1fr)]">
                                                <Field
                                                    htmlFor={`clause-id-${index}`}
                                                    label="Clause reference"
                                                >
                                                    <Input
                                                        id={`clause-id-${index}`}
                                                        value={clause.id}
                                                        onChange={(event) =>
                                                            edit(
                                                                'clauses',
                                                                content.clauses.map(
                                                                    (
                                                                        item,
                                                                        i,
                                                                    ) =>
                                                                        i ===
                                                                        index
                                                                            ? {
                                                                                  ...item,
                                                                                  id: event
                                                                                      .target
                                                                                      .value,
                                                                              }
                                                                            : item,
                                                                ),
                                                            )
                                                        }
                                                    />
                                                </Field>
                                                <Field
                                                    htmlFor={`clause-title-${index}`}
                                                    label="Clause title"
                                                >
                                                    <Input
                                                        id={`clause-title-${index}`}
                                                        value={clause.title}
                                                        onChange={(event) =>
                                                            edit(
                                                                'clauses',
                                                                content.clauses.map(
                                                                    (
                                                                        item,
                                                                        i,
                                                                    ) =>
                                                                        i ===
                                                                        index
                                                                            ? {
                                                                                  ...item,
                                                                                  title: event
                                                                                      .target
                                                                                      .value,
                                                                              }
                                                                            : item,
                                                                ),
                                                            )
                                                        }
                                                    />
                                                </Field>
                                            </div>
                                            <Field
                                                htmlFor={`clause-text-${index}`}
                                                label="Policy text"
                                                hint="Paste original wording. AI citations must match this text."
                                            >
                                                <Textarea
                                                    id={`clause-text-${index}`}
                                                    rows={7}
                                                    value={clause.text}
                                                    onChange={(event) =>
                                                        edit(
                                                            'clauses',
                                                            content.clauses.map(
                                                                (item, i) =>
                                                                    i === index
                                                                        ? {
                                                                              ...item,
                                                                              text: event
                                                                                  .target
                                                                                  .value,
                                                                          }
                                                                        : item,
                                                            ),
                                                        )
                                                    }
                                                    placeholder="Describe transaction categories, permitted uses, exclusions and required review."
                                                />
                                            </Field>
                                            {content.clauses.length > 1 && (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    className="self-start"
                                                    onClick={() =>
                                                        edit(
                                                            'clauses',
                                                            content.clauses.filter(
                                                                (_, i) =>
                                                                    i !== index,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <Trash2 />
                                                    Remove clause
                                                </Button>
                                            )}
                                        </div>
                                    ))}
                                    <Field
                                        htmlFor="priorities"
                                        label="Funding priorities"
                                        hint="Agent may propose priorities only within approved bills and available funds."
                                    >
                                        <Textarea
                                            id="priorities"
                                            value={content.priorities}
                                            onChange={(event) =>
                                                edit(
                                                    'priorities',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="Explain which obligations should receive attention first, and why."
                                        />
                                    </Field>
                                </Panel>
                            )}
                            {section === 'categories' && (
                                <Panel
                                    title="Transaction categories"
                                    description="Define your own vocabulary and boundaries."
                                    action={
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                edit('categories', [
                                                    ...content.categories,
                                                    {
                                                        id: `category-${content.categories.length + 1}`,
                                                        name: '',
                                                        description: '',
                                                        inclusions: '',
                                                        exclusions: '',
                                                        clause_ids:
                                                            content.clauses.map(
                                                                (item) =>
                                                                    item.id,
                                                            ),
                                                    },
                                                ])
                                            }
                                        >
                                            <Plus />
                                            Add category
                                        </Button>
                                    }
                                >
                                    {content.categories.length === 0 && (
                                        <p className="text-sm text-muted-foreground">
                                            No categories yet. Add your
                                            institution’s first category;
                                            unclear transactions always have a
                                            Needs review path.
                                        </p>
                                    )}
                                    {content.categories.map(
                                        (category, index) => (
                                            <div
                                                key={index}
                                                className="flex flex-col gap-4 border-b border-border pb-6"
                                            >
                                                <div className="flex items-center justify-between gap-3">
                                                    <Badge variant="outline">
                                                        Category {index + 1}
                                                    </Badge>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        aria-label={`Remove category ${index + 1}`}
                                                        onClick={() =>
                                                            edit(
                                                                'categories',
                                                                content.categories.filter(
                                                                    (_, i) =>
                                                                        i !==
                                                                        index,
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        <Trash2 />
                                                    </Button>
                                                </div>
                                                {(
                                                    [
                                                        'id',
                                                        'name',
                                                        'description',
                                                        'inclusions',
                                                        'exclusions',
                                                    ] as const
                                                ).map((key) => (
                                                    <Field
                                                        key={key}
                                                        htmlFor={`category-${index}-${key}`}
                                                        label={
                                                            {
                                                                id: 'Stable category ID',
                                                                name: 'Category name',
                                                                description:
                                                                    'What this category means',
                                                                inclusions:
                                                                    'Include these',
                                                                exclusions:
                                                                    'Do not include these',
                                                            }[key]
                                                        }
                                                    >
                                                        <Input
                                                            id={`category-${index}-${key}`}
                                                            value={
                                                                category[key] ??
                                                                ''
                                                            }
                                                            onChange={(event) =>
                                                                edit(
                                                                    'categories',
                                                                    content.categories.map(
                                                                        (
                                                                            item,
                                                                            i,
                                                                        ) =>
                                                                            i ===
                                                                            index
                                                                                ? {
                                                                                      ...item,
                                                                                      [key]: event
                                                                                          .target
                                                                                          .value,
                                                                                  }
                                                                                : item,
                                                                    ),
                                                                )
                                                            }
                                                        />
                                                    </Field>
                                                ))}
                                                <Field
                                                    htmlFor={`category-${index}-clauses`}
                                                    label="Source clauses"
                                                    hint="Comma-separated clause references from your policy, not invented citations."
                                                >
                                                    <Input
                                                        id={`category-${index}-clauses`}
                                                        value={category.clause_ids.join(
                                                            ', ',
                                                        )}
                                                        onChange={(event) =>
                                                            edit(
                                                                'categories',
                                                                content.categories.map(
                                                                    (
                                                                        item,
                                                                        i,
                                                                    ) =>
                                                                        i ===
                                                                        index
                                                                            ? {
                                                                                  ...item,
                                                                                  clause_ids:
                                                                                      event.target.value
                                                                                          .split(
                                                                                              ',',
                                                                                          )
                                                                                          .map(
                                                                                              (
                                                                                                  value,
                                                                                              ) =>
                                                                                                  value.trim(),
                                                                                          )
                                                                                          .filter(
                                                                                              Boolean,
                                                                                          ),
                                                                              }
                                                                            : item,
                                                                ),
                                                            )
                                                        }
                                                    />
                                                </Field>
                                            </div>
                                        ),
                                    )}
                                </Panel>
                            )}
                            {section === 'examples' && (
                                <Panel
                                    title="Policy examples"
                                    description="Show expected decisions before publishing."
                                    action={
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                edit('examples', [
                                                    ...content.examples,
                                                    {
                                                        description: '',
                                                        category_id:
                                                            'needs_review',
                                                    },
                                                ])
                                            }
                                        >
                                            <Plus />
                                            Add example
                                        </Button>
                                    }
                                >
                                    {content.examples.length === 0 && (
                                        <p className="text-sm text-muted-foreground">
                                            Add clear matches and confusing
                                            cases. Publication requires passing
                                            saved examples.
                                        </p>
                                    )}
                                    {content.examples.map((example, index) => (
                                        <div
                                            key={index}
                                            className="flex flex-col gap-4 border-b border-border pb-6"
                                        >
                                            <Field
                                                htmlFor={`example-${index}`}
                                                label={`Example ${index + 1}`}
                                            >
                                                <Textarea
                                                    id={`example-${index}`}
                                                    value={example.description}
                                                    onChange={(event) =>
                                                        edit(
                                                            'examples',
                                                            content.examples.map(
                                                                (item, i) =>
                                                                    i === index
                                                                        ? {
                                                                              ...item,
                                                                              description:
                                                                                  event
                                                                                      .target
                                                                                      .value,
                                                                          }
                                                                        : item,
                                                            ),
                                                        )
                                                    }
                                                    placeholder="Describe a representative transaction."
                                                />
                                            </Field>
                                            <Field
                                                htmlFor={`expected-${index}`}
                                                label="Expected category"
                                            >
                                                <Select
                                                    value={example.category_id}
                                                    onValueChange={(value) =>
                                                        edit(
                                                            'examples',
                                                            content.examples.map(
                                                                (item, i) =>
                                                                    i === index
                                                                        ? {
                                                                              ...item,
                                                                              category_id:
                                                                                  value,
                                                                          }
                                                                        : item,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger
                                                        id={`expected-${index}`}
                                                    >
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="needs_review">
                                                            Needs review
                                                        </SelectItem>
                                                        {content.categories.map(
                                                            (category) => (
                                                                <SelectItem
                                                                    key={
                                                                        category.id
                                                                    }
                                                                    value={
                                                                        category.id
                                                                    }
                                                                >
                                                                    {category.name ||
                                                                        category.id}
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                            </Field>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                className="self-start"
                                                onClick={() =>
                                                    edit(
                                                        'examples',
                                                        content.examples.filter(
                                                            (_, i) =>
                                                                i !== index,
                                                        ),
                                                    )
                                                }
                                            >
                                                <Trash2 />
                                                Remove example
                                            </Button>
                                        </div>
                                    ))}
                                </Panel>
                            )}
                            {section === 'controls' && (
                                <Panel
                                    title="Financial controls"
                                    description="Exact institution limits, stored as approved policy data."
                                >
                                    <Alert variant="warning">
                                        <AlertTitle>
                                            Not an AI estimate
                                        </AlertTitle>
                                        <AlertDescription>
                                            Enter explicit USDC amounts.
                                            Publication activates these controls
                                            through existing finance policy
                                            review. Automatic payments still
                                            require separate mandate and runtime
                                            authority.
                                        </AlertDescription>
                                    </Alert>
                                    <div className="grid gap-6 sm:grid-cols-2">
                                        {(
                                            Object.keys(
                                                content.controls,
                                            ) as (keyof Controls)[]
                                        ).map((key) => (
                                            <Field
                                                key={key}
                                                htmlFor={key}
                                                label={controlLabels[key]}
                                                hint="USDC · up to six decimals"
                                            >
                                                <Input
                                                    id={key}
                                                    inputMode="decimal"
                                                    value={
                                                        content.controls[key]
                                                    }
                                                    onChange={(event) =>
                                                        edit('controls', {
                                                            ...content.controls,
                                                            [key]: event.target
                                                                .value,
                                                        })
                                                    }
                                                />
                                            </Field>
                                        ))}
                                    </div>
                                    <Field
                                        htmlFor="clarifications"
                                        label="Unresolved questions"
                                        hint="One per line. Resolve in policy wording, then save a revision; unresolved questions block publication."
                                    >
                                        <Textarea
                                            id="clarifications"
                                            value={content.clarifications.join(
                                                '\n',
                                            )}
                                            onChange={(event) =>
                                                edit(
                                                    'clarifications',
                                                    event.target.value
                                                        .split('\n')
                                                        .filter(Boolean),
                                                )
                                            }
                                            placeholder="Anything your institution still needs to decide."
                                        />
                                    </Field>
                                </Panel>
                            )}
                            {section === 'playground' && (
                                <>
                                    <Panel
                                        title="Policy playground"
                                        description="Read the interpretation before trusting a label."
                                    >
                                        {!aiEnabled && (
                                            <Alert variant="warning">
                                                <AlertTitle>
                                                    AI disabled
                                                </AlertTitle>
                                                <AlertDescription>
                                                    Approved provider and
                                                    disclosure settings must be
                                                    enabled by operator. Tests
                                                    return Needs review while
                                                    unavailable.
                                                </AlertDescription>
                                            </Alert>
                                        )}
                                        {!queueReady && (
                                            <Alert variant="warning">
                                                <AlertTitle>
                                                    Queue setup required
                                                </AlertTitle>
                                                <AlertDescription>
                                                    Use database or Redis queue
                                                    with retry_after above 180
                                                    seconds; run worker for
                                                    policy-assessments. Saving
                                                    policy remains available.
                                                </AlertDescription>
                                            </Alert>
                                        )}
                                        <div className="flex flex-wrap gap-2">
                                            <Button
                                                type="button"
                                                size="sm"
                                                disabled={
                                                    !canAssess ||
                                                    assessmentHttp.processing
                                                }
                                                onClick={() => run('authoring')}
                                            >
                                                <Sparkles />
                                                Interpret policy
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                disabled={
                                                    !canAssess ||
                                                    assessmentHttp.processing
                                                }
                                                onClick={() => run('examples')}
                                            >
                                                <CheckCheck />
                                                Test examples
                                            </Button>
                                        </div>
                                        <Field
                                            htmlFor="transaction-evidence"
                                            label="Transaction evidence"
                                            hint="Only test data you are allowed to send to configured provider. No transaction is changed."
                                        >
                                            <Textarea
                                                id="transaction-evidence"
                                                value={description}
                                                onChange={(event) =>
                                                    setDescription(
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="Describe purpose, goods or service, and known supporting evidence."
                                                rows={5}
                                            />
                                        </Field>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="self-start"
                                            disabled={
                                                !canAssess ||
                                                !description.trim() ||
                                                assessmentHttp.processing
                                            }
                                            onClick={() =>
                                                run('classification')
                                            }
                                        >
                                            <FlaskConical />
                                            Classify example
                                        </Button>
                                    </Panel>
                                    {[
                                        'authoring',
                                        'examples',
                                        'classification',
                                    ].map((kind) => {
                                        const item = latest(kind);
                                        return item ? (
                                            <Panel
                                                key={kind}
                                                title={
                                                    {
                                                        authoring:
                                                            'Interpretation',
                                                        examples:
                                                            'Example results',
                                                        classification:
                                                            'Classification',
                                                    }[kind] ?? kind
                                                }
                                                description="Bound to this exact saved policy revision."
                                            >
                                                <JudgmentView item={item} />
                                                {item.state ===
                                                    'needs_review' && (
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        className="self-start"
                                                        disabled={
                                                            assessmentHttp.processing ||
                                                            !canAssess
                                                        }
                                                        onClick={() =>
                                                            run(kind)
                                                        }
                                                    >
                                                        Retry assessment
                                                    </Button>
                                                )}
                                            </Panel>
                                        ) : null;
                                    })}
                                    <Panel
                                        title="Plan advice"
                                        description="Published policy applied to existing approved obligations."
                                    >
                                        {planAdvice.length === 0 ? (
                                            <p className="text-sm text-muted-foreground">
                                                No plan advice yet. Operator
                                                must enable published-policy
                                                advice. Financial planning and
                                                execution work without it.
                                            </p>
                                        ) : (
                                            planAdvice.map((item) => (
                                                <div
                                                    key={item.id}
                                                    className="border-b border-border pb-4"
                                                >
                                                    <p className="text-xs text-muted-foreground">
                                                        Run #
                                                        {item.input
                                                            .workflow_run_id ??
                                                            'Unknown'}{' '}
                                                        / Invoice version #
                                                        {item.input
                                                            .invoice_version_id ??
                                                            'Unknown'}{' '}
                                                        / Policy revision #
                                                        {
                                                            item.institution_policy_version_id
                                                        }
                                                        {item.institution_policy_version_id !==
                                                        current?.institution_policy_version_id
                                                            ? ' / Historical policy'
                                                            : ''}
                                                    </p>
                                                    <JudgmentView item={item} />
                                                </div>
                                            ))
                                        )}
                                    </Panel>
                                </>
                            )}
                            {section === 'publication' && (
                                <Panel
                                    title="Review & publish"
                                    description="Independent approval of exact tested policy."
                                >
                                    {current && !current.controls_active && (
                                        <Alert variant="warning">
                                            <AlertTitle>
                                                Published controls superseded
                                            </AlertTitle>
                                            <AlertDescription>
                                                A separate finance policy was
                                                activated after this policy was
                                                published, so these limits no
                                                longer govern payments. The
                                                clauses and categories stay
                                                published; publish a revision to
                                                restore institution control.
                                            </AlertDescription>
                                        </Alert>
                                    )}
                                    <div className="flex flex-wrap gap-2">
                                        <Badge
                                            variant={
                                                latest('authoring')?.state ===
                                                    'completed' &&
                                                !latest('authoring')?.result
                                                    ?.questions?.length &&
                                                latest('authoring')?.result
                                                    ?.needs_review === false
                                                    ? 'success-light'
                                                    : 'warning-light'
                                            }
                                        >
                                            Interpretation
                                        </Badge>
                                        <Badge
                                            variant={
                                                latest('examples')?.result
                                                    ?.all_passed
                                                    ? 'success-light'
                                                    : 'warning-light'
                                            }
                                        >
                                            Examples
                                        </Badge>
                                        <Badge variant="outline">
                                            {selected
                                                ? `Revision #${selected.id}`
                                                : 'Unsaved'}
                                        </Badge>
                                    </div>
                                    {selected && (
                                        <div className="flex flex-col gap-2">
                                            <p className="text-xs text-muted-foreground">
                                                Exact content digest
                                            </p>
                                            <code className="break-all text-xs">
                                                {selected.content_digest}
                                            </code>
                                            <p className="text-sm">
                                                {content.clauses.length} clauses
                                                · {content.categories.length}{' '}
                                                categories ·{' '}
                                                {content.examples.length}{' '}
                                                expected examples. Author
                                                account #{selected.created_by}.
                                            </p>
                                        </div>
                                    )}
                                    <p className="text-sm text-muted-foreground">
                                        Reviewer must be different from author.
                                        Changed policy makes earlier material
                                        payment evidence stale; historical
                                        assessments remain unchanged.
                                    </p>
                                    <div className="flex flex-col gap-4 border-y border-border py-4">
                                        <h2 className="text-sm font-semibold">
                                            Revision comparison
                                        </h2>
                                        <p className="text-xs text-muted-foreground">
                                            {comparison
                                                ? `Compared with revision #${comparison.id}: ${comparison.title}`
                                                : 'Initial policy. Every clause and control needs independent review.'}
                                        </p>
                                        <p className="text-sm">
                                            {changedClauses.length} new or
                                            changed clauses. Categories{' '}
                                            {JSON.stringify(
                                                content.categories,
                                            ) ===
                                            JSON.stringify(
                                                comparison?.content.categories,
                                            )
                                                ? 'unchanged'
                                                : 'changed'}
                                            . Examples{' '}
                                            {JSON.stringify(
                                                content.examples,
                                            ) ===
                                            JSON.stringify(
                                                comparison?.content.examples,
                                            )
                                                ? 'unchanged'
                                                : 'changed'}
                                            .
                                        </p>
                                        {changedClauses.map((clause) => (
                                            <div
                                                key={clause.id}
                                                className="flex flex-col gap-1 text-sm"
                                            >
                                                <span className="font-medium">
                                                    {clause.id}: {clause.title}
                                                </span>
                                                <p className="whitespace-pre-wrap">
                                                    {clause.text}
                                                </p>
                                            </div>
                                        ))}
                                        <dl className="grid gap-3 sm:grid-cols-2">
                                            {(
                                                Object.keys(
                                                    content.controls,
                                                ) as (keyof Controls)[]
                                            ).map((key) => (
                                                <div
                                                    key={key}
                                                    className="flex flex-col gap-1 text-sm"
                                                >
                                                    <dt className="text-muted-foreground">
                                                        {controlLabels[key]}
                                                    </dt>
                                                    <dd className="font-medium tabular-nums">
                                                        {comparison
                                                            ? `${comparison.content.controls[key]} → `
                                                            : ''}
                                                        {content.controls[key]}{' '}
                                                        USDC
                                                    </dd>
                                                </div>
                                            ))}
                                        </dl>
                                    </div>
                                    <Field
                                        htmlFor="review-reason"
                                        label="Review reason"
                                    >
                                        <Textarea
                                            id="review-reason"
                                            value={reason}
                                            onChange={(event) =>
                                                setReason(event.target.value)
                                            }
                                            placeholder="Explain policy source, resolved questions and tested outcomes."
                                        />
                                    </Field>
                                    <div className="flex items-start gap-3">
                                        <Checkbox
                                            id="publication-attestation"
                                            checked={attested}
                                            onCheckedChange={(value) =>
                                                setAttested(value === true)
                                            }
                                        />
                                        <Label
                                            htmlFor="publication-attestation"
                                            className="text-sm"
                                        >
                                            I reviewed this exact policy,
                                            categories, examples and financial
                                            controls. Publication approves no
                                            individual payment.
                                        </Label>
                                    </div>
                                    <Button
                                        type="button"
                                        size="sm"
                                        className="self-start"
                                        disabled={
                                            !canPublish ||
                                            !selected ||
                                            selected.created_by === user.id ||
                                            dirty ||
                                            !attested ||
                                            !reason.trim() ||
                                            publishHttp.processing
                                        }
                                        onClick={publishRevision}
                                    >
                                        <ShieldCheck />
                                        {publishHttp.processing
                                            ? 'Publishing…'
                                            : 'Publish policy'}
                                    </Button>
                                    {(!canPublish ||
                                        selected?.created_by === user.id) && (
                                        <p className="text-xs text-muted-foreground">
                                            Independent admin reviewer required.
                                            Authors cannot publish their own
                                            policy.
                                        </p>
                                    )}
                                    <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                        <History className="size-4" />
                                        <span>
                                            Every save creates immutable
                                            revision. Published history is
                                            retained.
                                        </span>
                                    </div>
                                </Panel>
                            )}
                        </div>
                    </div>
                </main>
            </div>
            <Dialog
                open={pendingVersion !== null}
                onOpenChange={(open) => {
                    if (!open) setPendingVersion(null);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Unsaved changes</DialogTitle>
                        <DialogDescription>
                            Saved revisions remain intact. Discard current
                            unsaved edits and switch policy?
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setPendingVersion(null)}
                        >
                            Keep editing
                        </Button>
                        <Button
                            type="button"
                            onClick={() => {
                                if (pendingVersion) openVersion(pendingVersion);
                                setPendingVersion(null);
                            }}
                        >
                            Discard edits
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
