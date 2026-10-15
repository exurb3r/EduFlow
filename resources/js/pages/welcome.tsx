import { useEffect, useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    Check,
    CheckCircle2,
    ChevronDown,
    ExternalLink,
} from 'lucide-react';

import { dashboard, login } from '@/routes';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';

interface WelcomeProps {
    canRegister?: boolean;
}

interface IntakeItem {
    id: string;
    student: string;
    grade: string;
    source: string;
    amount: string;
    fit: number;
    tag: string;
    note: string;
}

interface PayRule {
    num: number;
    name: string;
    rule: string;
    badge: string;
}

interface VendorRow {
    vendor: string;
    category: string;
    score: number;
    amount: string;
    schedule: string;
}

interface FaqItem {
    q: string;
    a: string;
}

const SAMPLE_STUDENTS: IntakeItem[] = [
    {
        id: 's-1',
        student: 'Maya K.',
        grade: 'Grade 11 · Tuition wire',
        source: 'Bank wire verified',
        amount: '$3,200.00',
        fit: 99,
        tag: 'reconciled batch',
        note: 'Term two science curriculum tuition settled cleanly via direct institution wire.',
    },
    {
        id: 's-2',
        student: 'Daniel B.',
        grade: 'Grade 9 · Counter deposit',
        source: 'Cashier desk receipt',
        amount: '$1,850.00',
        fit: 94,
        tag: 'dual verified',
        note: 'Cash intake counter check logged with cashier stamp and verified by bursar.',
    },
    {
        id: 's-3',
        student: 'Ines M.',
        grade: 'Grade 12 · Digital portal',
        source: 'Card terminal pass',
        amount: '$2,400.00',
        fit: 91,
        tag: 'instant entry',
        note: 'Senior graduation fee and lab access pass cleared with instant digital receipt.',
    },
];

const BORING_CHORES: string[] = [
    'match bank wires to student rosters',
    'catch unverified tuition checks',
    'quarantine five thousand dollar reserve',
    'check campus fiber bill against contract',
    'flag recurring software price spikes',
    'route lab chemical purchase to dean',
    'pause ventilation repair before buffer dip',
    'generate penny perfect receipts',
    'lock unapproved vendor wallet addresses',
    'balance departmental science allocations',
    'count who paid second semester fees',
    'draft supplier payment authorization',
    'verify vendor address on school register',
    'keep cryptographic proof of every transfer',
    'alert finance director for phone review',
    'stop accidental budget overdrafts',
];

const RULES_ENGINE: PayRule[] = [
    {
        num: 1,
        name: 'goal',
        rule: 'reconcile all tuition batches daily',
        badge: 'school rule',
    },
    {
        num: 2,
        name: 'reserve guard',
        rule: 'five thousand dollars locked forever',
        badge: 'untouchable',
    },
    {
        num: 3,
        name: 'ask first',
        rule: 'any invoice over fifty dollars waits for staff',
        badge: 'dual control',
    },
    {
        num: 4,
        name: 'auto ceiling',
        rule: 'fifty dollars max per routine payout',
        badge: 'hard cap',
    },
    {
        num: 5,
        name: 'never pay',
        rule: 'vendors missing from approved registry',
        badge: 'strict block',
    },
    {
        num: 6,
        name: 'settlement',
        rule: 'one second finality on modern payment rails',
        badge: 'instant proof',
    },
];

const VENDOR_SHORTLIST: VendorRow[] = [
    {
        vendor: 'Metro Fiber Networks',
        category: 'Campus Fiber',
        score: 98,
        amount: '$30.00',
        schedule: 'Due Friday',
    },
    {
        vendor: 'Apex Scientific Supply',
        category: 'Chemistry Reagents',
        score: 92,
        amount: '$90.00',
        schedule: 'Manager review',
    },
    {
        vendor: 'Precision Mechanical',
        category: 'Filter Unit',
        score: 87,
        amount: '$200.00',
        schedule: 'Paused by guard',
    },
    {
        vendor: 'Cloud Learning Systems',
        category: 'Software Seats',
        score: 94,
        amount: '$45.00',
        schedule: 'Auto scheduled',
    },
    {
        vendor: 'Atlas Athletic Goods',
        category: 'Gym Equipment',
        score: 79,
        amount: '$340.00',
        schedule: 'Board sign off',
    },
];

const FAQS: FaqItem[] = [
    {
        q: 'Does OpenSinop spend school money on its own?',
        a: 'Only routine bills under your strict limit that you explicitly pre authorize, like monthly campus internet. Everything else requires an authorized human staff member to click yes.',
    },
    {
        q: 'What prevents money from going to the wrong person?',
        a: 'Every vendor address must be checked and confirmed in your institutional register beforehand. The rules engine stops any disbursement to an unlisted recipient in code, not in a prompt.',
    },
    {
        q: 'Do students or parents need digital wallets or crypto?',
        a: 'No. Parents and students pay through standard bank wires, cashier counter cash, or credit cards. OpenSinop handles the ledger and treasury protection in the background.',
    },
    {
        q: 'How does the emergency reserve stay safe?',
        a: 'You choose a safety buffer, such as five thousand dollars. If any pending invoice would cause treasury liquidity to breach that floor, the system safely halts payment and alerts your team.',
    },
    {
        q: 'Is there a monthly subscription fee or seat license?',
        a: 'None. OpenSinop is open source software released under the MIT license. You host it on your own institutional infrastructure with full ownership of your records.',
    },
    {
        q: 'Can our staff freeze the payment wallet at any moment?',
        a: 'Yes. One switch freezes the entire treasury wallet instantly. All background evaluations continue, but zero dollars can leave until an administrator turns it back on.',
    },
];

export default function Welcome({ canRegister = false }: WelcomeProps) {
    const { auth } = usePage().props;
    const [selectedStudentId, setSelectedStudentId] = useState<string>('s-1');
    const [pendingApproval, setPendingApproval] = useState<
        'pending' | 'approved' | 'rejected'
    >('pending');
    const [openFaq, setOpenFaq] = useState<number | null>(0);
    const [domainInput, setDomainInput] = useState<string>('westview.edu');

    const activeStudent =
        SAMPLE_STUDENTS.find((s) => s.id === selectedStudentId) ||
        SAMPLE_STUDENTS[0];

    return (
        <div className="min-h-screen bg-[#FAFAF9] text-[#1C1917] font-sans antialiased selection:bg-[#1C1917] selection:text-white relative">
            <Head title="OpenSinop — Simple School Finance and Bill Payments" />

            {/* Radiant Ambient Light Background */}
            <div className="absolute inset-0 pointer-events-none overflow-hidden -z-10">
                <div className="absolute top-0 left-1/2 -translate-x-1/2 w-[1100px] h-[550px] bg-gradient-to-b from-stone-200/40 via-amber-50/20 to-transparent blur-3xl rounded-full" />
                <div className="absolute top-[800px] left-1/2 -translate-x-1/2 w-[850px] h-[450px] bg-gradient-to-b from-stone-200/30 via-stone-100/10 to-transparent blur-3xl rounded-full" />
            </div>

            {/* Elevated Light Navigation Header */}
            <header className="sticky top-0 z-50 border-b border-stone-200/70 bg-[#FAFAF9]/85 backdrop-blur-xl">
                <div className="mx-auto flex max-w-6xl items-center justify-between px-4 sm:px-6 h-16">
                    <Link href="/" className="flex items-center gap-2.5 group">
                        <div className="flex size-7 items-center justify-center rounded-lg bg-[#1C1917] text-white font-serif font-bold text-sm shadow-[0_2px_8px_rgba(0,0,0,0.12)] transition-transform group-hover:scale-105">
                            O
                        </div>
                        <div className="flex flex-col">
                            <span className="font-serif text-base font-bold tracking-tight text-[#1C1917]">
                                OpenSinop
                            </span>
                        </div>
                    </Link>

                    <nav className="hidden md:flex items-center gap-7 text-xs font-medium text-stone-600">
                        <a
                            href="#product"
                            className="hover:text-stone-900 transition-colors"
                        >
                            What it does
                        </a>
                        <a
                            href="#how"
                            className="hover:text-stone-900 transition-colors"
                        >
                            How it works
                        </a>
                        <a
                            href="#rules"
                            className="hover:text-stone-900 transition-colors"
                        >
                            Your rules
                        </a>
                        <a
                            href="#receipts"
                            className="hover:text-stone-900 transition-colors"
                        >
                            Receipts
                        </a>
                        <a
                            href="#pricing"
                            className="hover:text-stone-900 transition-colors"
                        >
                            Pricing
                        </a>
                        <Link
                            href="/skills/design"
                            className="hover:text-stone-900 transition-colors"
                        >
                            Skills Studio
                        </Link>
                    </nav>

                    <div className="flex items-center gap-2.5">
                        {auth?.user ? (
                            <Link href={dashboard()}>
                                <Button
                                    size="sm"
                                    className="rounded-full text-xs font-semibold px-4 h-8.5 bg-[#1C1917] text-white hover:bg-stone-800 shadow-xs"
                                >
                                    Dashboard
                                    <ArrowRight className="size-3.5 ml-1" />
                                </Button>
                            </Link>
                        ) : (
                            <>
                                <Link href={login()}>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="rounded-full text-xs font-medium h-8.5 px-3 text-stone-700 hover:text-stone-950 hover:bg-stone-200/50"
                                    >
                                        Sign in
                                    </Button>
                                </Link>
                                <a href="/finance">
                                    <Button
                                        size="sm"
                                        className="rounded-full text-xs font-semibold px-4 h-8.5 bg-[#1C1917] text-white hover:bg-stone-800 shadow-[0_2px_10px_rgba(0,0,0,0.08)]"
                                    >
                                        Open Console
                                    </Button>
                                </a>
                            </>
                        )}
                    </div>
                </div>
            </header>

            <main className="flex flex-col gap-24 sm:gap-36 pt-16 sm:pt-24 pb-24">
                {/* 1. HERO HOOK SECTION (FANCY LIGHT MODE) */}
                <section className="mx-auto flex max-w-4xl flex-col items-center px-4 sm:px-6 text-center">
                    {/* Eyebrow Pill */}
                    <div className="mb-5 inline-flex items-center gap-2 rounded-full border border-stone-200/80 bg-white px-3.5 py-1 text-xs font-mono text-stone-600 shadow-[0_2px_10px_rgba(0,0,0,0.02)]">
                        <span className="relative flex size-2">
                            <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
                            <span className="relative inline-flex size-2 rounded-full bg-emerald-500" />
                        </span>
                        <span>
                            FOR COLLEGES, ACADEMIES, INSTITUTES AND SCHOOLS
                        </span>
                    </div>

                    <h1
                        style={{ textWrap: 'balance' }}
                        className="font-serif text-4xl sm:text-6xl lg:text-7xl font-bold tracking-tight text-[#1C1917] leading-[1.08]"
                    >
                        Your school fees are arriving.
                        <br />
                        <span className="italic font-normal text-stone-600">
                            Your bills shouldn't guess.
                        </span>
                    </h1>

                    <p
                        style={{ textWrap: 'pretty' }}
                        className="mt-6 max-w-2xl text-base sm:text-lg text-stone-600 leading-relaxed font-sans"
                    >
                        OpenSinop reconciles tuition where families pay, locks
                        your emergency reserve first, and proposes small,
                        measured bill payments, then waits for your yes before a
                        single dollar moves.
                    </p>

                    <div className="mt-8 flex flex-wrap items-center justify-center gap-3.5">
                        <a href="/finance">
                            <Button
                                size="lg"
                                className="rounded-full px-7 py-3 text-sm font-semibold shadow-[0_4px_16px_rgba(0,0,0,0.12)] bg-[#1C1917] text-white hover:bg-stone-800 transition-all hover:-translate-y-0.5"
                            >
                                Launch treasury mission
                                <ArrowRight className="size-4 ml-1.5" />
                            </Button>
                        </a>
                        <a href="#product">
                            <Button
                                variant="outline"
                                size="lg"
                                className="rounded-full px-6 py-3 text-sm font-semibold border-stone-300 bg-white text-stone-800 hover:bg-stone-50 shadow-xs"
                            >
                                See what it does
                            </Button>
                        </a>
                    </div>

                    {/* FANCY COCKPIT TERMINAL SIMULATOR */}
                    <div className="mt-14 w-full text-left rounded-2xl border border-stone-200/90 bg-white p-5 sm:p-7 shadow-[0_20px_50px_rgba(0,0,0,0.05)] ring-1 ring-stone-900/[0.03]">
                        {/* Browser Window Bar */}
                        <div className="flex flex-col sm:flex-row sm:items-center justify-between pb-4 border-b border-stone-200/80 gap-3">
                            <div className="flex items-center gap-2">
                                <span className="size-2.5 rounded-full bg-[#FF5F56] border border-black/10" />
                                <span className="size-2.5 rounded-full bg-[#FFBD2E] border border-black/10" />
                                <span className="size-2.5 rounded-full bg-[#27C93F] border border-black/10" />
                                <div className="ml-3 rounded-full border border-stone-200 bg-stone-50 px-3 py-0.5 font-mono text-[11px] text-stone-600 flex items-center gap-1.5">
                                    <span className="size-1.5 rounded-full bg-emerald-500" />
                                    <span>westview.edu/treasury</span>
                                </div>
                            </div>
                            <div className="flex items-center gap-3 font-mono text-[11px] text-stone-500">
                                <span>3 intake records in batch</span>
                                <span className="text-stone-300">|</span>
                                <span className="font-semibold text-stone-800">
                                    $50,000.00 verified
                                </span>
                                <span className="text-stone-300">|</span>
                                <span className="inline-flex items-center gap-1 font-semibold text-amber-700 bg-amber-50 px-2 py-0.5 rounded-full border border-amber-200/70">
                                    <span className="size-1.5 rounded-full bg-amber-500" />
                                    1 approval waiting
                                </span>
                            </div>
                        </div>

                        {/* Two column interactive cockpit */}
                        <div className="mt-6 grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                            {/* Left: Students intake list */}
                            <div className="lg:col-span-7 flex flex-col gap-2.5">
                                <div className="flex items-center justify-between px-1 text-xs font-mono text-stone-500 uppercase tracking-wider">
                                    <span>Verified Fee Intake</span>
                                    <span>Match Quality</span>
                                </div>

                                {SAMPLE_STUDENTS.map((item) => {
                                    const active =
                                        selectedStudentId === item.id;
                                    return (
                                        <div
                                            key={item.id}
                                            role="button"
                                            tabIndex={0}
                                            onClick={() =>
                                                setSelectedStudentId(item.id)
                                            }
                                            onKeyDown={(e) => {
                                                if (
                                                    e.key === 'Enter' ||
                                                    e.key === ' '
                                                ) {
                                                    e.preventDefault();
                                                    setSelectedStudentId(
                                                        item.id,
                                                    );
                                                }
                                            }}
                                            className={`p-3.5 rounded-xl border transition-all cursor-pointer flex items-center justify-between gap-3 ${
                                                active
                                                    ? 'border-stone-900 bg-stone-50/80 shadow-[0_2px_12px_rgba(0,0,0,0.04)] ring-1 ring-stone-900/10'
                                                    : 'border-stone-200/70 bg-white hover:border-stone-400/80 hover:bg-stone-50/40'
                                            }`}
                                        >
                                            <div className="flex items-center gap-3">
                                                <div
                                                    className={`flex size-8 shrink-0 items-center justify-center rounded-lg font-mono text-xs font-bold transition-colors ${
                                                        active
                                                            ? 'bg-stone-900 text-white'
                                                            : 'bg-stone-100 text-stone-700 border border-stone-200'
                                                    }`}
                                                >
                                                    {item.student[0]}
                                                </div>
                                                <div>
                                                    <div className="flex items-center gap-2">
                                                        <span className="font-semibold text-sm text-stone-900">
                                                            {item.student}
                                                        </span>
                                                        <span className="text-xs text-stone-500">
                                                            {item.grade}
                                                        </span>
                                                    </div>
                                                    <p className="text-xs text-stone-600 line-clamp-1 mt-0.5">
                                                        "{item.note}"
                                                    </p>
                                                </div>
                                            </div>

                                            <div className="text-right shrink-0">
                                                <span className="font-mono text-xs font-bold text-stone-900 block">
                                                    {item.amount}
                                                </span>
                                                <span className="inline-flex items-center gap-1 font-mono text-[10px] text-emerald-700 bg-emerald-50 px-1.5 py-0.5 rounded border border-emerald-200/60 font-semibold">
                                                    <Check className="size-2.5" />
                                                    <span>{item.fit} fit</span>
                                                </span>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>

                            {/* Right: GrowTendril style approval card with light elevation */}
                            <div className="lg:col-span-5 flex flex-col gap-3">
                                <div className="px-1 text-xs font-mono text-stone-500 uppercase tracking-wider">
                                    Decision Engine · Needs Your Yes
                                </div>

                                <div className="rounded-xl border border-stone-200 bg-stone-50/70 p-5 flex flex-col justify-between shadow-2xs">
                                    <div>
                                        <div className="flex items-center justify-between pb-3 border-b border-stone-200 text-xs font-mono text-stone-500">
                                            <span className="font-semibold text-stone-700">
                                                PROPOSED EXPENSE
                                            </span>
                                            <Badge
                                                variant="outline"
                                                className="font-mono text-[10px] bg-white border-stone-300 text-stone-700"
                                            >
                                                POLICY V3
                                            </Badge>
                                        </div>

                                        <h3 className="mt-3 font-serif text-base font-bold text-stone-900">
                                            Pay Metro Fiber Networks $30.00 for
                                            campus internet?
                                        </h3>

                                        <p className="mt-2 text-xs text-stone-600 leading-relaxed">
                                            Verified recurring utility. Complies
                                            with your fifty dollar auto limit
                                            and preserves your five thousand
                                            dollar reserve buffer.
                                        </p>

                                        <div className="mt-4 flex flex-col gap-1.5 font-mono text-[11px] text-stone-600 bg-white p-3 rounded-lg border border-stone-200/80">
                                            <div className="flex justify-between">
                                                <span>Recipients:</span>
                                                <span className="text-stone-900 font-semibold">
                                                    Metro Fiber verified
                                                </span>
                                            </div>
                                            <div className="flex justify-between">
                                                <span>Safety buffer:</span>
                                                <span className="text-stone-900 font-semibold">
                                                    $5,000.00 intact
                                                </span>
                                            </div>
                                            <div className="flex justify-between">
                                                <span>Settlement rail:</span>
                                                <span className="text-stone-900 font-semibold">
                                                    One second confirmation
                                                </span>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Action button states */}
                                    <div className="mt-5 pt-3 border-t border-stone-200 flex items-center gap-2">
                                        {pendingApproval === 'pending' ? (
                                            <>
                                                <Button
                                                    size="sm"
                                                    onClick={() =>
                                                        setPendingApproval(
                                                            'approved',
                                                        )
                                                    }
                                                    className="flex-1 rounded-md text-xs font-semibold h-9 bg-stone-900 text-white hover:bg-stone-800 shadow-xs"
                                                >
                                                    Approve Payment
                                                </Button>
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        setPendingApproval(
                                                            'rejected',
                                                        )
                                                    }
                                                    className="rounded-md text-xs font-semibold h-9 border-stone-300 bg-white text-stone-700 hover:bg-stone-100"
                                                >
                                                    Not now
                                                </Button>
                                            </>
                                        ) : pendingApproval === 'approved' ? (
                                            <div className="w-full flex items-center justify-center gap-2 py-2 text-xs font-mono text-emerald-800 font-semibold bg-emerald-50 rounded-md border border-emerald-300">
                                                <CheckCircle2 className="size-4 text-emerald-600" />
                                                <span>
                                                    Approved. Receipt recorded.
                                                </span>
                                            </div>
                                        ) : (
                                            <div className="w-full flex items-center justify-center gap-2 py-2 text-xs font-mono text-stone-600 rounded-md border border-stone-300 bg-white">
                                                <span>
                                                    Disbursement postponed.
                                                </span>
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setPendingApproval(
                                                            'pending',
                                                        )
                                                    }
                                                    className="underline text-stone-900 font-semibold ml-1"
                                                >
                                                    Reset
                                                </button>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                {/* 2. INTRODUCING OPENSINOP NARRATIVE SECTION */}
                <section
                    id="product"
                    className="mx-auto max-w-4xl px-4 sm:px-6"
                >
                    <span className="text-xs font-mono uppercase tracking-widest text-stone-500 block mb-3 font-semibold">
                        INTRODUCING OPENSINOP
                    </span>

                    <p className="text-xl sm:text-2xl font-serif text-stone-900 leading-relaxed font-bold">
                        Somewhere right now, a campus internet bill is due.
                        Someone in the science faculty is ordering chemistry
                        supplies with a paper form. A facilities repair is about
                        to accidentally wipe out your school savings cushion.
                        You will never see all of it in time, there is too much
                        paperwork, and term moves too fast.
                    </p>

                    <p className="mt-6 text-sm sm:text-base text-stone-600 leading-relaxed">
                        OpenSinop reconciles the money for you. It tracks
                        tuition intake across cashier counters and bank wires,
                        guards your emergency buffer so savings remain
                        untouchable, and routes routine bills into safe
                        execution lanes. You approve the spend. It records the
                        digital receipt, and tomorrow starts cleanly from there.
                    </p>
                </section>

                {/* 3. THE BORING WORK (FANCY LIGHT PILL CLOUD) */}
                <section className="mx-auto max-w-5xl px-4 sm:px-6">
                    <div className="mb-10 text-center sm:text-left">
                        <span className="text-xs font-mono uppercase tracking-widest text-stone-500 font-semibold">
                            THE BORING WORK
                        </span>
                        <h2
                            style={{ textWrap: 'balance' }}
                            className="mt-2 text-3xl sm:text-4xl font-bold tracking-tight text-stone-900 font-serif"
                        >
                            All the finance chores,
                            <br />
                            done before the morning bell.
                        </h2>
                    </div>

                    {/* Word pill cloud */}
                    <div className="flex flex-wrap gap-2.5">
                        {BORING_CHORES.map((chore, idx) => (
                            <span
                                key={chore + idx}
                                className="inline-flex items-center rounded-lg border border-stone-200/90 bg-white px-3.5 py-2 text-xs font-medium text-stone-700 shadow-[0_1px_3px_rgba(0,0,0,0.02)] hover:border-stone-400 hover:text-stone-950 hover:shadow-[0_4px_12px_rgba(0,0,0,0.04)] hover:-translate-y-0.5 transition-all duration-200"
                            >
                                {chore}
                            </span>
                        ))}
                    </div>

                    <p className="mt-8 font-mono text-xs text-stone-500">
                        None of it is clever. All of it is repeated. That is
                        exactly the work worth handing to an operator.
                    </p>
                </section>

                {/* 4. HOW A CYCLE WORKS: 3 STAGES WITH CRISP LIGHT CARDS */}
                <section
                    id="how"
                    className="border-y border-stone-200/80 bg-stone-100/50 py-20"
                >
                    <div className="mx-auto max-w-6xl px-4 sm:px-6">
                        <div className="mb-12">
                            <span className="text-xs font-mono uppercase tracking-widest text-stone-500 font-semibold">
                                HOW A CYCLE WORKS
                            </span>
                            <h2
                                style={{ textWrap: 'balance' }}
                                className="mt-2 text-3xl sm:text-4xl font-bold tracking-tight text-stone-900 font-serif"
                            >
                                Records it. Checks it.
                                <br />
                                Asks before it spends.
                            </h2>
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                            {/* Card 1: Records intake */}
                            <div className="rounded-xl border border-stone-200 bg-white p-6 flex flex-col justify-between shadow-[0_4px_20px_rgba(0,0,0,0.03)] hover:border-stone-300 transition-colors">
                                <div>
                                    <span className="font-mono text-xs text-stone-500 block mb-3 font-semibold">
                                        STAGE 01 · VERIFICATION
                                    </span>
                                    <div className="space-y-1.5 font-mono text-xs border border-stone-200 bg-stone-50 p-3 rounded-lg mb-4 text-stone-600">
                                        <div className="flex justify-between">
                                            <span>read cashier ledger</span>
                                            <span className="text-stone-900 font-semibold">
                                                done
                                            </span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span>reconcile bank wires</span>
                                            <span className="text-stone-900 font-semibold">
                                                done
                                            </span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span>lock emergency floor</span>
                                            <span className="font-bold text-stone-900">
                                                $5k held
                                            </span>
                                        </div>
                                    </div>
                                    <h3 className="font-serif text-lg font-bold text-stone-900">
                                        Records the intake.
                                    </h3>
                                    <p className="mt-2 text-xs text-stone-600 leading-relaxed">
                                        Reads the cashier entries and bank
                                        settlements. It keeps only verified
                                        receipts and links each dollar directly
                                        to its student record.
                                    </p>
                                </div>
                            </div>

                            {/* Card 2: Checks the evidence */}
                            <div className="rounded-xl border border-stone-200 bg-white p-6 flex flex-col justify-between shadow-[0_4px_20px_rgba(0,0,0,0.03)] hover:border-stone-300 transition-colors">
                                <div>
                                    <span className="font-mono text-xs text-stone-500 block mb-3 font-semibold">
                                        STAGE 02 · INTEGRITY CHECK
                                    </span>
                                    <div className="space-y-1.5 font-mono text-xs border border-stone-200 bg-stone-50 p-3 rounded-lg mb-4">
                                        <div className="flex justify-between items-center text-stone-900 font-semibold">
                                            <span>Maya K. wire match</span>
                                            <span className="text-emerald-700 bg-emerald-50 px-1.5 py-0.5 rounded border border-emerald-200 text-[11px]">
                                                99 fit ✓
                                            </span>
                                        </div>
                                        <div className="text-[11px] text-stone-500">
                                            bank stamp verified · dual logged
                                            today
                                        </div>
                                    </div>
                                    <h3 className="font-serif text-lg font-bold text-stone-900">
                                        Checks the evidence.
                                    </h3>
                                    <p className="mt-2 text-xs text-stone-600 leading-relaxed">
                                        Collections and suppliers are verified
                                        on confirmed bank records, not verbal
                                        claims. Open the source statement behind
                                        every single transaction.
                                    </p>
                                </div>
                            </div>

                            {/* Card 3: Asks before it spends */}
                            <div className="rounded-xl border border-stone-200 bg-white p-6 flex flex-col justify-between shadow-[0_4px_20px_rgba(0,0,0,0.03)] hover:border-stone-300 transition-colors">
                                <div>
                                    <span className="font-mono text-xs text-stone-500 block mb-3 font-semibold">
                                        STAGE 03 · POLICY BOUNDS
                                    </span>
                                    <div className="space-y-1.5 font-mono text-xs border border-stone-200 bg-stone-50 p-3 rounded-lg mb-4">
                                        <div className="flex justify-between text-stone-600">
                                            <span>routine $30 bill:</span>
                                            <span className="text-stone-900 font-semibold">
                                                complies
                                            </span>
                                        </div>
                                        <div className="flex justify-between text-stone-600">
                                            <span>lab $90 bill:</span>
                                            <span className="text-stone-900 font-semibold">
                                                ask you first
                                            </span>
                                        </div>
                                        <div className="flex justify-between text-stone-600">
                                            <span>vent repair $200:</span>
                                            <span className="text-rose-700 font-semibold">
                                                halted by rule
                                            </span>
                                        </div>
                                    </div>
                                    <h3 className="font-serif text-lg font-bold text-stone-900">
                                        Asks before it spends.
                                    </h3>
                                    <p className="mt-2 text-xs text-stone-600 leading-relaxed">
                                        Your limits are enforced in mathematical
                                        code. Anything breaching your reserve
                                        buffer is blocked. Routine bills
                                        disburse smoothly; large ones wait for
                                        your yes.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                {/* 5. YOUR RULES: YOUR LIMITS AREN'T SUGGESTIONS. THEY'RE CODE. */}
                <section id="rules" className="mx-auto max-w-5xl px-4 sm:px-6">
                    <div className="mb-10">
                        <span className="text-xs font-mono uppercase tracking-widest text-stone-500 font-semibold">
                            YOUR RULES
                        </span>
                        <h2
                            style={{ textWrap: 'balance' }}
                            className="mt-2 text-3xl sm:text-4xl font-bold tracking-tight text-stone-900 font-serif"
                        >
                            Your limits aren't suggestions.
                            <br />
                            They're code.
                        </h2>
                        <p className="mt-3 max-w-xl text-sm text-stone-600">
                            The disbursement policy is evaluated by a
                            deterministic rules engine on every payment, not by
                            an artificial intelligence prompt.
                        </p>
                    </div>

                    <div className="rounded-xl border border-stone-200 bg-white p-6 sm:p-8 shadow-[0_4px_24px_rgba(0,0,0,0.03)]">
                        <div className="flex items-center justify-between pb-3 border-b border-stone-200 font-mono text-xs text-stone-500">
                            <span>TREASURY POLICY · WESTVIEW COLLEGE</span>
                            <Badge
                                variant="outline"
                                className="font-mono text-[10px] bg-stone-50 border-stone-300"
                            >
                                POLICY V3
                            </Badge>
                        </div>

                        <div className="mt-4 divide-y divide-stone-100 font-mono text-xs">
                            {RULES_ENGINE.map((r) => (
                                <div
                                    key={r.num}
                                    className="py-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-2"
                                >
                                    <div className="flex items-center gap-3">
                                        <span className="text-stone-400 w-4 font-bold">
                                            {r.num}
                                        </span>
                                        <span className="font-bold text-stone-900 uppercase">
                                            {r.name}
                                        </span>
                                        <span className="text-stone-600">
                                            {r.rule}
                                        </span>
                                    </div>
                                    <Badge
                                        variant="outline"
                                        className="w-fit text-[10px] font-mono uppercase bg-stone-50 border-stone-200 text-stone-700"
                                    >
                                        {r.badge}
                                    </Badge>
                                </div>
                            ))}
                        </div>

                        <div className="mt-6 pt-5 border-t border-stone-200 grid grid-cols-1 sm:grid-cols-2 gap-6 text-xs font-mono text-stone-600 bg-stone-50/60 p-4 rounded-lg">
                            <div>
                                <span className="text-3xl font-bold text-stone-900 block font-serif">
                                    0
                                </span>
                                <span className="font-semibold text-stone-700">
                                    payments allowed above the fifty dollar auto
                                    cap
                                </span>
                            </div>
                            <div>
                                <span className="font-bold text-stone-900 block mb-1">
                                    Nothing is guessed at
                                </span>
                                <p className="text-[11px] leading-relaxed text-stone-600">
                                    If the operator cannot confirm the vendor
                                    address against your school registry, it
                                    halts and asks instead of paying the wrong
                                    recipient.
                                </p>
                            </div>
                        </div>
                    </div>
                </section>

                {/* 6. VENDORS / PAYABLES DIRECTORY */}
                <section className="mx-auto max-w-5xl px-4 sm:px-6">
                    <div className="mb-10">
                        <span className="text-xs font-mono uppercase tracking-widest text-stone-500 font-semibold">
                            PAYABLES DIRECTORY
                        </span>
                        <h2
                            style={{ textWrap: 'balance' }}
                            className="mt-2 text-3xl sm:text-4xl font-bold tracking-tight text-stone-900 font-serif"
                        >
                            It knows who your school owes,
                            <br />
                            and when each bill is due.
                        </h2>
                        <p className="mt-3 max-w-xl text-sm text-stone-600">
                            Not a messy folder of paper invoices, an auditable
                            directory with clear payment rules and one click
                            approvals.
                        </p>
                    </div>

                    {/* Table card */}
                    <div className="rounded-xl border border-stone-200 bg-white overflow-hidden shadow-[0_4px_24px_rgba(0,0,0,0.03)]">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left font-mono text-xs">
                                <thead className="bg-stone-50 border-b border-stone-200 text-stone-600 uppercase text-[11px]">
                                    <tr>
                                        <th className="py-3 px-5">Supplier</th>
                                        <th className="py-3 px-5">Category</th>
                                        <th className="py-3 px-5">
                                            Safety Score
                                        </th>
                                        <th className="py-3 px-5">Amount</th>
                                        <th className="py-3 px-5 text-right">
                                            Status
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-stone-100">
                                    {VENDOR_SHORTLIST.map((row) => (
                                        <tr
                                            key={row.vendor}
                                            className="hover:bg-stone-50/70 transition-colors"
                                        >
                                            <td className="py-4 px-5 font-semibold text-stone-900">
                                                {row.vendor}
                                            </td>
                                            <td className="py-4 px-5 text-stone-600">
                                                {row.category}
                                            </td>
                                            <td className="py-4 px-5">
                                                <span className="inline-flex items-center gap-1 text-emerald-800 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200 font-semibold">
                                                    <Check className="size-3 text-emerald-600" />
                                                    {row.score}
                                                </span>
                                            </td>
                                            <td className="py-4 px-5 font-bold text-stone-900">
                                                {row.amount}
                                            </td>
                                            <td className="py-4 px-5 text-right text-stone-600">
                                                {row.schedule}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                {/* 7. RECEIPTS: EVERY DOLLAR LEAVES A RECEIPT (ELEVATED CARD LOOK) */}
                <section
                    id="receipts"
                    className="border-y border-stone-200/80 bg-stone-100/50 py-20"
                >
                    <div className="mx-auto max-w-5xl px-4 sm:px-6">
                        <div className="mb-10">
                            <span className="text-xs font-mono uppercase tracking-widest text-stone-500 font-semibold">
                                AUDIT PROOF
                            </span>
                            <h2
                                style={{ textWrap: 'balance' }}
                                className="mt-2 text-3xl sm:text-4xl font-bold tracking-tight text-stone-900 font-serif"
                            >
                                Every dollar leaves a receipt.
                            </h2>
                            <p className="mt-2 max-w-xl text-sm text-stone-600">
                                Who was paid, how much, why, who approved it,
                                and what transaction hash settled it, kept
                                permanently with the expense.
                            </p>
                        </div>

                        {/* Payment receipt card with fancy certificates feel */}
                        <div className="grid grid-cols-1 md:grid-cols-12 gap-6 items-center">
                            <div className="md:col-span-7">
                                <div className="rounded-xl border border-stone-300 bg-white p-6 shadow-[0_8px_30px_rgba(0,0,0,0.04)] font-mono text-xs relative overflow-hidden">
                                    <div className="flex items-center justify-between pb-3 border-b border-stone-200">
                                        <span className="text-stone-600 font-semibold">
                                            EDUFLOW · PAYMENT RECEIPT
                                        </span>
                                        <Badge
                                            variant="outline"
                                            className="text-[10px] bg-stone-50 border-stone-300 text-stone-700"
                                        >
                                            VERIFIED PROOF
                                        </Badge>
                                    </div>

                                    <div className="mt-4 space-y-2.5">
                                        <div className="flex justify-between">
                                            <span className="text-stone-500">
                                                For:
                                            </span>
                                            <span className="font-semibold text-stone-900">
                                                Monthly campus fiber internet
                                            </span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-stone-500">
                                                Paid to:
                                            </span>
                                            <span className="font-semibold text-stone-900">
                                                Metro Fiber Networks
                                            </span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-stone-500">
                                                Amount:
                                            </span>
                                            <span className="font-bold text-stone-900 text-sm">
                                                $30.00
                                            </span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-stone-500">
                                                Approved by:
                                            </span>
                                            <span className="font-semibold text-stone-900">
                                                Finance bursar · 09:14
                                            </span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-stone-500">
                                                Policy:
                                            </span>
                                            <span className="font-semibold text-stone-900">
                                                v3 · within budget ceiling
                                            </span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-stone-500">
                                                Rail confirmation:
                                            </span>
                                            <span className="font-semibold text-stone-900">
                                                Single block settled
                                            </span>
                                        </div>
                                    </div>

                                    <div className="mt-5 pt-3 border-t border-stone-200 flex items-center justify-between text-[11px] text-stone-500">
                                        <span>Receipt: 0xa8e7aeff430199bc</span>
                                        <span className="text-emerald-700 font-semibold">
                                            Zero late fees
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div className="md:col-span-5 flex flex-col gap-4">
                                <div className="p-6 rounded-xl border border-stone-200 bg-white text-xs shadow-xs">
                                    <span className="font-mono text-stone-500 block mb-1 uppercase text-[10px] font-semibold">
                                        MEASURED OUTCOME
                                    </span>
                                    <span className="font-serif text-xl font-bold text-stone-900 block">
                                        100 percent on time disbursement
                                    </span>
                                    <p className="mt-2 text-stone-600 leading-relaxed font-sans text-xs">
                                        Campus internet never interrupted. Zero
                                        vendor collection calls. Ledger balanced
                                        instantly without month end scramble.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                {/* 8. PRICING: OPEN SOURCE ECONOMICS */}
                <section
                    id="pricing"
                    className="mx-auto max-w-5xl px-4 sm:px-6"
                >
                    <div className="mb-10">
                        <span className="text-xs font-mono uppercase tracking-widest text-stone-500 font-semibold">
                            OPEN SOURCE ECONOMICS
                        </span>
                        <h2
                            style={{ textWrap: 'balance' }}
                            className="mt-2 text-3xl sm:text-4xl font-bold tracking-tight text-stone-900 font-serif"
                        >
                            Open source software.
                            <br />
                            Zero subscription fees.
                        </h2>
                        <p className="mt-3 max-w-xl text-sm text-stone-600">
                            No per seat charges and no vendor lock in. Run
                            OpenSinop on your own school servers with complete
                            data sovereignty.
                        </p>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div className="rounded-xl border border-stone-200 bg-white p-6 flex flex-col justify-between shadow-[0_4px_20px_rgba(0,0,0,0.02)]">
                            <div>
                                <span className="font-mono text-xs text-stone-500 block mb-2 uppercase font-semibold">
                                    SINGLE CAMPUS
                                </span>
                                <div className="font-serif text-3xl font-bold text-stone-900">
                                    $0
                                </div>
                                <span className="font-mono text-xs text-stone-500 block mt-1">
                                    Free under MIT license
                                </span>
                                <p className="mt-4 text-xs text-stone-600 leading-relaxed">
                                    Complete institutional treasury engine, fee
                                    intake verification, and policy guardrails.
                                </p>
                            </div>
                            <div className="mt-6 pt-4 border-t border-stone-200 font-mono text-xs text-stone-500">
                                Self hosted deployment
                            </div>
                        </div>

                        <div className="rounded-xl border-2 border-stone-900 bg-white p-6 flex flex-col justify-between shadow-[0_10px_30px_rgba(0,0,0,0.06)] relative">
                            <div>
                                <div className="flex items-center justify-between mb-2">
                                    <span className="font-mono text-xs text-stone-900 font-bold uppercase">
                                        COMMUNITY EDITION
                                    </span>
                                    <Badge className="font-mono text-[10px] bg-stone-900 text-white">
                                        RECOMMENDED
                                    </Badge>
                                </div>
                                <div className="font-serif text-3xl font-bold text-stone-900">
                                    $0
                                </div>
                                <span className="font-mono text-xs text-stone-500 block mt-1">
                                    Full source access
                                </span>
                                <p className="mt-4 text-xs text-stone-600 leading-relaxed">
                                    Multi user staff roles, two lane approval
                                    execution, audit exports, and Arc rails
                                    connectivity.
                                </p>
                            </div>
                            <div className="mt-6 pt-4 border-t border-stone-200 font-mono text-xs font-bold text-stone-900">
                                Unlimited users and records
                            </div>
                        </div>

                        <div className="rounded-xl border border-stone-200 bg-white p-6 flex flex-col justify-between shadow-[0_4px_20px_rgba(0,0,0,0.02)]">
                            <div>
                                <span className="font-mono text-xs text-stone-500 block mb-2 uppercase font-semibold">
                                    DATA SOVEREIGNTY
                                </span>
                                <div className="font-serif text-3xl font-bold text-stone-900">
                                    100%
                                </div>
                                <span className="font-mono text-xs text-stone-500 block mt-1">
                                    Your institutional database
                                </span>
                                <p className="mt-4 text-xs text-stone-600 leading-relaxed">
                                    All accounting records, student rosters, and
                                    bank wires live strictly on your servers.
                                </p>
                            </div>
                            <div className="mt-6 pt-4 border-t border-stone-200 font-mono text-xs text-stone-500">
                                Zero third party tracking
                            </div>
                        </div>
                    </div>
                </section>

                {/* 9. ON YOUR TERMS: 4 SECURITY PILLARS */}
                <section className="mx-auto max-w-5xl px-4 sm:px-6">
                    <div className="mb-10">
                        <span className="text-xs font-mono uppercase tracking-widest text-stone-500 font-semibold">
                            ON YOUR TERMS
                        </span>
                        <h2
                            style={{ textWrap: 'balance' }}
                            className="mt-2 text-3xl sm:text-4xl font-bold tracking-tight text-stone-900 font-serif"
                        >
                            You stay in charge.
                        </h2>
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div className="p-5 rounded-xl border border-stone-200 bg-white shadow-2xs">
                            <span className="font-mono text-[11px] text-stone-500 block mb-2 uppercase font-semibold">
                                APPROVAL REQUIRED
                            </span>
                            <h3 className="font-serif text-base font-bold text-stone-900">
                                Asks first
                            </h3>
                            <p className="mt-2 text-xs text-stone-600 leading-relaxed">
                                Every major invoice asks by default. Routine
                                utilities under fifty dollars auto pay only when
                                pre approved.
                            </p>
                        </div>

                        <div className="p-5 rounded-xl border border-stone-200 bg-white shadow-2xs">
                            <span className="font-mono text-[11px] text-stone-500 block mb-2 uppercase font-semibold">
                                PENNY PERFECT
                            </span>
                            <h3 className="font-serif text-base font-bold text-stone-900">
                                Exact base units
                            </h3>
                            <p className="mt-2 text-xs text-stone-600 leading-relaxed">
                                All math runs in strict integer units. Zero
                                floating point rounding drift across academic
                                terms.
                            </p>
                        </div>

                        <div className="p-5 rounded-xl border border-stone-200 bg-white shadow-2xs">
                            <span className="font-mono text-[11px] text-stone-500 block mb-2 uppercase font-semibold">
                                INSTANT SAFETY
                            </span>
                            <h3 className="font-serif text-base font-bold text-stone-900">
                                Freeze any time
                            </h3>
                            <p className="mt-2 text-xs text-stone-600 leading-relaxed">
                                One physical switch freezes disbursements. Scans
                                and reconciliations continue while money stays
                                locked.
                            </p>
                        </div>

                        <div className="p-5 rounded-xl border border-stone-200 bg-white shadow-2xs">
                            <span className="font-mono text-[11px] text-stone-500 block mb-2 uppercase font-semibold">
                                PERMANENT OWNERSHIP
                            </span>
                            <h3 className="font-serif text-base font-bold text-stone-900">
                                No subscription
                            </h3>
                            <p className="mt-2 text-xs text-stone-600 leading-relaxed">
                                No monthly bills, no license expirations, and no
                                vendor lock in. You control your software
                                permanently.
                            </p>
                        </div>
                    </div>
                </section>

                {/* 10. HONEST QUESTIONS & ANSWERS (ACCORDION) */}
                <section className="mx-auto max-w-5xl px-4 sm:px-6">
                    <div className="mb-10">
                        <span className="text-xs font-mono uppercase tracking-widest text-stone-500 font-semibold">
                            HONEST ANSWERS
                        </span>
                        <h2
                            style={{ textWrap: 'balance' }}
                            className="mt-2 text-3xl sm:text-4xl font-bold tracking-tight text-stone-900 font-serif"
                        >
                            Things people ask first.
                        </h2>
                    </div>

                    <div className="divide-y divide-stone-200 border-y border-stone-200">
                        {FAQS.map((faq, index) => {
                            const isOpen = openFaq === index;
                            return (
                                <div key={faq.q} className="py-4">
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setOpenFaq(isOpen ? null : index)
                                        }
                                        className="w-full flex items-center justify-between text-left font-serif text-base font-bold text-stone-900 py-1 hover:text-stone-700 transition-colors"
                                    >
                                        <span>{faq.q}</span>
                                        <ChevronDown
                                            className={`size-4 text-stone-500 transition-transform duration-200 ${
                                                isOpen ? 'rotate-180' : ''
                                            }`}
                                        />
                                    </button>
                                    {isOpen && (
                                        <p className="mt-3 text-xs sm:text-sm text-stone-600 leading-relaxed pr-8">
                                            {faq.a}
                                        </p>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </section>

                {/* 11. FINAL CONVERSION ANCHOR (FANCY LIGHT CARD) */}
                <section className="mx-auto max-w-5xl w-full px-4 sm:px-6">
                    <div className="rounded-2xl border border-stone-300/80 bg-gradient-to-b from-white via-white to-stone-50/70 p-8 sm:p-14 text-center shadow-[0_20px_60px_rgba(0,0,0,0.05)]">
                        <span className="text-xs font-mono uppercase tracking-widest text-stone-500 block mb-2 font-semibold">
                            FOR YOUR SCHOOL
                        </span>
                        <h2
                            style={{ textWrap: 'balance' }}
                            className="text-3xl sm:text-5xl font-bold tracking-tight text-stone-900 font-serif"
                        >
                            Ready to run your school treasury?
                        </h2>
                        <p
                            style={{ textWrap: 'pretty' }}
                            className="mt-3 max-w-xl mx-auto text-sm text-stone-600 leading-relaxed font-sans"
                        >
                            Enter your institution domain. OpenSinop organizes
                            your tuition intake, guards your emergency buffer,
                            and sets up your first verified bill release.
                        </p>

                        {/* Interactive domain launch form */}
                        <div className="mt-8 flex flex-col sm:flex-row items-center justify-center gap-2.5 max-w-md mx-auto">
                            <div className="w-full rounded-full border border-stone-300 bg-white px-4 py-2 text-xs font-mono text-stone-900 text-left shadow-2xs focus-within:border-stone-500 transition-colors">
                                <input
                                    type="text"
                                    value={domainInput}
                                    onChange={(e) =>
                                        setDomainInput(e.target.value)
                                    }
                                    placeholder="yourschool.edu"
                                    className="w-full bg-transparent outline-none font-mono text-xs text-stone-900"
                                />
                            </div>
                            <a
                                href="/finance"
                                className="w-full sm:w-auto shrink-0"
                            >
                                <Button
                                    size="lg"
                                    className="w-full sm:w-auto rounded-full px-7 py-2 text-xs font-semibold h-10 bg-stone-900 text-white hover:bg-stone-800 shadow-[0_2px_12px_rgba(0,0,0,0.12)]"
                                >
                                    Open console
                                </Button>
                            </a>
                        </div>

                        <div className="mt-6 font-mono text-[11px] text-stone-500">
                            Free and open source. Zero subscription fees. Full
                            data sovereignty.
                        </div>
                    </div>
                </section>
            </main>

            {/* Clean Light Footer */}
            <footer className="border-t border-stone-200/80 bg-white py-12 text-xs text-stone-500 font-mono">
                <div className="mx-auto max-w-6xl px-4 sm:px-6 flex flex-col sm:flex-row items-center justify-between gap-6">
                    <div className="flex flex-col gap-1 text-center sm:text-left">
                        <span className="font-serif text-sm font-bold text-stone-900">
                            OpenSinop
                        </span>
                        <span className="font-sans text-xs">
                            Your school treasury operator. Evidence in, bounded
                            action out.
                        </span>
                    </div>

                    <div className="flex flex-wrap items-center justify-center gap-6">
                        <a
                            href="https://github.com/koamishin/OpenSinop"
                            target="_blank"
                            rel="noopener noreferrer"
                            className="hover:text-stone-900 transition-colors inline-flex items-center gap-1"
                        >
                            <span>Source Repository</span>
                            <ExternalLink className="size-3" />
                        </a>
                        <Link
                            href="/skills/design"
                            className="hover:text-stone-900 transition-colors"
                        >
                            Skills Studio
                        </Link>
                        <a
                            href="/finance"
                            className="hover:text-stone-900 transition-colors"
                        >
                            Finance Panel
                        </a>
                        <span>MIT License</span>
                    </div>
                </div>
            </footer>
        </div>
    );
}
