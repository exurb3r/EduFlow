import { useEffect, useRef, useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    ArrowUpRight,
    Banknote,
    Check,
    CheckCircle2,
    Coins,
    ExternalLink,
    FileText,
    Lock,
    School,
    Shield,
    Sparkles,
    Wifi,
    Zap,
} from 'lucide-react';

import { dashboard, login } from '@/routes';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';

interface WelcomeProps {
    canRegister?: boolean;
}

interface PayableRecord {
    id: string;
    code: string;
    title: string;
    vendor: string;
    category: string;
    amount: string;
    amountNumber: number;
    verdict: 'CLEAR' | 'REVIEW' | 'PAUSED';
    badgeVariant: 'outline' | 'secondary' | 'destructive' | 'default';
    editorialNote: string;
}

const PAYABLES: PayableRecord[] = [
    {
        id: 'rec-01',
        code: 'FOLIO 104',
        title: 'Campus Internet Service',
        vendor: 'Metro Fiber Networks',
        category: 'Utilities',
        amount: '$30.00',
        amountNumber: 30,
        verdict: 'CLEAR',
        badgeVariant: 'outline',
        editorialNote:
            'Well under your fifty dollar automatic threshold. Vendor credentials match the school directory. Disburses on Friday without manual paperwork.',
    },
    {
        id: 'rec-02',
        code: 'FOLIO 105',
        title: 'Chemistry Lab Reagents',
        vendor: 'Apex Scientific Supply',
        category: 'Instruction',
        amount: '$90.00',
        amountNumber: 90,
        verdict: 'REVIEW',
        badgeVariant: 'secondary',
        editorialNote:
            'Invoice exceeds the fifty dollar automatic ceiling. EduFlow places a temporary hold and routes an instant approval prompt to the finance director.',
    },
    {
        id: 'rec-03',
        code: 'FOLIO 106',
        title: 'Ventilation Motor Assembly',
        vendor: 'Precision Mechanical',
        category: 'Facilities',
        amount: '$200.00',
        amountNumber: 200,
        verdict: 'PAUSED',
        badgeVariant: 'destructive',
        editorialNote:
            'Settlement right now would compromise your five thousand dollar emergency reserve. The guard safely pauses release until fresh tuition batches settle.',
    },
];

const EDITORIAL_CREED = [
    'COLLECT',
    'FEES',
    'CLEANLY.',
    'PROTECT',
    'RESERVES.',
    'PAY',
    'BILLS',
    'ON',
    'TIME',
    'WITH',
    'TOTAL',
    'CONFIDENCE.',
];

const ESSAYS = [
    {
        numeral: 'I',
        kicker: 'INTAKE DISCIPLINE',
        title: 'Independent Batch Verification',
        body: 'Tuition wire transfers and counter deposits record in clean batches. Every intake entry reconciles through an independent checker before releasing into spendable budget.',
        statLabel: 'VERIFIED INTAKE',
        statValue: '$50,000.00',
    },
    {
        numeral: 'II',
        kicker: 'CAPITAL PRESERVATION',
        title: 'Untouchable Safety Reserves',
        body: 'EduFlow isolates emergency contingency capital first. The software guarantees that no routine utility or facilities invoice ever dips into your sacred rainy day reserve.',
        statLabel: 'LOCKED BUFFER',
        statValue: '$5,000.00',
    },
    {
        numeral: 'III',
        kicker: 'DUAL DISBURSEMENT',
        title: 'Two Lane Approval Flow',
        body: 'Small recurring invoices disburse automatically on modern rails. Major capital purchases halt instantly for authorized administrative confirmation on mobile devices.',
        statLabel: 'SETTLEMENT SPEED',
        statValue: 'ONE SECOND',
    },
];

export default function Welcome({ canRegister = false }: WelcomeProps) {
    const { auth } = usePage().props;
    const [selectedId, setSelectedId] = useState<string>('rec-01');
    const [simulatedPaid, setSimulatedPaid] = useState<boolean>(false);
    const [revealedCount, setRevealedCount] = useState<number>(0);
    const creedRef = useRef<HTMLDivElement>(null);

    const activeItem = PAYABLES.find((p) => p.id === selectedId) || PAYABLES[0];

    useEffect(() => {
        const observer = new IntersectionObserver(
            ([entry]) => {
                if (entry.isIntersecting) {
                    let idx = 0;
                    const timer = setInterval(() => {
                        idx += 1;
                        setRevealedCount(idx);
                        if (idx >= EDITORIAL_CREED.length) {
                            clearInterval(timer);
                        }
                    }, 55);
                }
            },
            { threshold: 0.25 },
        );

        if (creedRef.current) observer.observe(creedRef.current);
        return () => observer.disconnect();
    }, []);

    return (
        <div className="min-h-screen bg-[#FBFBFA] text-[#161616] antialiased selection:bg-[#E30613] selection:text-white dark:bg-[#0C0D0E] dark:text-[#EDEDED]">
            <Head title="EduFlow — Swiss Precision and Editorial School Finance" />

            {/* Editorial Running Masthead */}
            <div className="border-b border-border/80 bg-background/50 px-4 py-1.5 font-mono text-[11px] text-muted-foreground uppercase tracking-widest hidden sm:block">
                <div className="mx-auto flex max-w-7xl items-center justify-between">
                    <div className="flex items-center gap-3">
                        <span className="size-1.5 bg-[#E30613]" />
                        <span>EDC GAZETTE VOL. 26</span>
                        <span className="text-border">/</span>
                        <span>SCHOOL TREASURY SPECIFICATION</span>
                    </div>
                    <div className="flex items-center gap-4">
                        <span>ZURICH · BOSTON · LONDON</span>
                        <span className="text-border">/</span>
                        <span>MIT OPEN SOURCE</span>
                    </div>
                </div>
            </div>

            {/* Swiss Broadsheet Header Navigation */}
            <header className="sticky top-0 z-50 border-b border-border/80 bg-background/90 backdrop-blur-md">
                <div className="mx-auto flex max-w-7xl items-center justify-between px-4 py-3 sm:px-6">
                    <Link href="/" className="flex items-center gap-3 group">
                        <div className="flex size-7 items-center justify-center rounded-sm bg-[#E30613] text-white font-mono text-xs font-bold shadow-xs transition-transform group-hover:scale-105">
                            E
                        </div>
                        <div className="flex flex-col">
                            <span className="font-serif text-base font-bold tracking-tight text-foreground">
                                EduFlow
                            </span>
                            <span className="font-mono text-[10px] uppercase tracking-wider text-muted-foreground -mt-0.5">
                                Institutional Treasury
                            </span>
                        </div>
                    </Link>

                    <nav className="hidden md:flex items-center gap-7 text-xs font-mono uppercase tracking-wider text-muted-foreground">
                        <a
                            href="#essays"
                            className="hover:text-foreground transition-colors"
                        >
                            01 / Principles
                        </a>
                        <a
                            href="#simulator"
                            className="hover:text-foreground transition-colors"
                        >
                            02 / Live Ledger
                        </a>
                        <Link
                            href="/skills/design"
                            className="hover:text-foreground transition-colors"
                        >
                            03 / Skills Hub
                        </Link>
                    </nav>

                    <div className="flex items-center gap-2.5">
                        {auth?.user ? (
                            <Link href={dashboard()}>
                                <Button
                                    size="sm"
                                    className="font-mono text-xs font-medium px-4 h-8.5 rounded-md"
                                >
                                    Dashboard
                                    <ArrowRight className="size-3 ml-1" />
                                </Button>
                            </Link>
                        ) : (
                            <>
                                <Link href={login()}>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="font-mono text-xs uppercase tracking-wider h-8.5 px-3 rounded-md"
                                    >
                                        Staff Portal
                                    </Button>
                                </Link>
                                <a
                                    href="/finance"
                                    className="hidden sm:inline-block"
                                >
                                    <Button
                                        size="sm"
                                        className="bg-[#E30613] text-white hover:bg-[#C20510] font-mono text-xs font-semibold px-4 h-8.5 rounded-md shadow-xs"
                                    >
                                        Finance Console
                                    </Button>
                                </a>
                            </>
                        )}
                    </div>
                </div>
            </header>

            <main className="mx-auto max-w-7xl px-4 sm:px-6 py-12 sm:py-20 flex flex-col gap-24 sm:gap-32">
                {/* 1. HERO BROADSHEET: Editorial Typography meets Swiss Mathematical Grid */}
                <section className="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-12 items-start border-b border-border/80 pb-16 sm:pb-24">
                    <div className="lg:col-span-7 flex flex-col items-start">
                        {/* Kicker badge */}
                        <div className="mb-6 inline-flex items-center gap-2 rounded-full border border-border bg-card px-3.5 py-1 text-xs font-mono text-muted-foreground shadow-2xs">
                            <span className="size-1.5 rounded-full bg-[#E30613]" />
                            <span className="font-semibold text-foreground">
                                TREASURY GOVERNANCE
                            </span>
                            <span className="text-border">|</span>
                            <span>HUMAN MONITORED</span>
                        </div>

                        {/* Master Broadsheet Title */}
                        <h1
                            style={{ textWrap: 'balance' }}
                            className="font-serif text-4xl sm:text-6xl lg:text-7xl font-bold tracking-tight text-foreground leading-[1.04]"
                        >
                            Track School Fees.
                            <br />
                            <span className="italic font-normal">
                                Pay Routine Bills
                            </span>
                            <br />
                            With Total Confidence.
                        </h1>

                        <p
                            style={{ textWrap: 'pretty' }}
                            className="mt-6 max-w-xl text-base sm:text-lg text-muted-foreground leading-relaxed"
                        >
                            EduFlow bridges tuition collection to supplier
                            disbursement. Routine bills under your chosen limit
                            disburse automatically. High value requisitions
                            pause for manager review. Your school never
                            overspends.
                        </p>

                        {/* Modern Action Group with shadcn Buttons */}
                        <div className="mt-8 flex flex-wrap items-center gap-3.5">
                            <a href="/finance">
                                <Button
                                    size="lg"
                                    className="bg-foreground text-background hover:bg-foreground/90 font-mono text-xs font-semibold uppercase tracking-wider px-6 h-11 rounded-md shadow-xs"
                                >
                                    Open Finance Console
                                    <ArrowRight className="size-3.5 ml-2" />
                                </Button>
                            </a>
                            <Link href={login()}>
                                <Button
                                    variant="outline"
                                    size="lg"
                                    className="border-border hover:bg-muted font-mono text-xs font-semibold uppercase tracking-wider px-6 h-11 rounded-md"
                                >
                                    Student Portal Login
                                </Button>
                            </Link>
                        </div>

                        {/* Citation Footnote Row */}
                        <div className="mt-8 pt-6 border-t border-border/80 w-full flex flex-wrap items-center gap-6 font-mono text-xs text-muted-foreground">
                            <div className="flex items-center gap-1.5">
                                <Check className="size-3.5 text-[#E30613]" />
                                <span>Penny perfect base units</span>
                            </div>
                            <div className="flex items-center gap-1.5">
                                <Check className="size-3.5 text-[#E30613]" />
                                <span>Reserve buffer quarantined</span>
                            </div>
                            <div className="flex items-center gap-1.5">
                                <Check className="size-3.5 text-[#E30613]" />
                                <span>Staff mobile verification</span>
                            </div>
                        </div>
                    </div>

                    {/* Right column: Swiss Modular Ledger Card using shadcn Card */}
                    <div className="lg:col-span-5">
                        <Card className="rounded-xl border border-border/90 bg-card p-6 shadow-xs">
                            <CardHeader className="p-0 pb-4 border-b border-border/80 flex flex-row items-center justify-between">
                                <div>
                                    <span className="font-mono text-[10px] uppercase tracking-widest text-muted-foreground block">
                                        FOLIO ARCHIVE / 2026
                                    </span>
                                    <CardTitle className="font-serif text-lg font-bold text-foreground mt-0.5">
                                        Westview Treasury Balance
                                    </CardTitle>
                                </div>
                                <Badge
                                    variant="outline"
                                    className="font-mono text-[10px] tracking-wider uppercase border-border"
                                >
                                    <span className="size-1 rounded-full bg-[#E30613] mr-1" />
                                    BALANCED
                                </Badge>
                            </CardHeader>

                            <CardContent className="p-0 pt-5 flex flex-col gap-5">
                                <div>
                                    <span className="font-mono text-[11px] uppercase tracking-wider text-muted-foreground block">
                                        TOTAL COLLECTED INTAKE
                                    </span>
                                    <span className="text-3xl sm:text-4xl font-mono font-bold tracking-tight text-foreground block mt-1">
                                        $50,000.00
                                    </span>
                                </div>

                                {/* Swiss Precision Segmented Bar */}
                                <div>
                                    <div className="h-2.5 w-full rounded-sm bg-muted overflow-hidden flex">
                                        <div
                                            style={{ width: '10%' }}
                                            className="bg-[#E30613]"
                                            title="Emergency Reserve $5k"
                                        />
                                        <div
                                            style={{ width: '30%' }}
                                            className="bg-foreground"
                                            title="Department Budget $15k"
                                        />
                                        <div
                                            style={{ width: '6.5%' }}
                                            className="bg-muted-foreground/60"
                                            title="Paid Bills $3.25k"
                                        />
                                        <div
                                            style={{ width: '53.5%' }}
                                            className="bg-border"
                                            title="Buffer $26.75k"
                                        />
                                    </div>

                                    {/* Column Legend */}
                                    <div className="mt-4 grid grid-cols-2 gap-2.5 font-mono text-[11px] text-muted-foreground">
                                        <div className="flex items-center justify-between p-2 rounded-sm bg-muted/30 border border-border/50">
                                            <span className="flex items-center gap-1.5">
                                                <span className="size-2 bg-[#E30613] rounded-2xs" />
                                                <span>Reserve</span>
                                            </span>
                                            <span className="font-bold text-foreground">
                                                $5,000
                                            </span>
                                        </div>
                                        <div className="flex items-center justify-between p-2 rounded-sm bg-muted/30 border border-border/50">
                                            <span className="flex items-center gap-1.5">
                                                <span className="size-2 bg-foreground rounded-2xs" />
                                                <span>Department</span>
                                            </span>
                                            <span className="font-bold text-foreground">
                                                $15,000
                                            </span>
                                        </div>
                                        <div className="flex items-center justify-between p-2 rounded-sm bg-muted/30 border border-border/50">
                                            <span className="flex items-center gap-1.5">
                                                <span className="size-2 bg-muted-foreground/60 rounded-2xs" />
                                                <span>Settled</span>
                                            </span>
                                            <span className="font-bold text-foreground">
                                                $3,250
                                            </span>
                                        </div>
                                        <div className="flex items-center justify-between p-2 rounded-sm bg-muted/30 border border-border/50">
                                            <span className="flex items-center gap-1.5">
                                                <span className="size-2 bg-border rounded-2xs" />
                                                <span>Buffer</span>
                                            </span>
                                            <span className="font-bold text-foreground">
                                                $26,750
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <Separator className="bg-border/80" />

                                <div className="flex items-center justify-between font-mono text-xs text-muted-foreground">
                                    <span>RULE DISCIPLINE:</span>
                                    <span className="text-foreground font-semibold">
                                        FIFTY DOLLAR CEILING / ONE SECOND ARC
                                        RAIL
                                    </span>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </section>

                {/* 2. THREE ESSAYS: Broadsheet Editorial Columns */}
                <section
                    id="essays"
                    className="flex flex-col gap-10 border-b border-border/80 pb-20 sm:pb-28"
                >
                    <div className="flex flex-col md:flex-row md:items-end justify-between gap-4">
                        <div>
                            <span className="font-mono text-xs uppercase tracking-widest text-[#E30613] block mb-1">
                                DISCOURSE 01 · SYSTEM GOVERNANCE
                            </span>
                            <h2 className="font-serif text-3xl sm:text-4xl font-bold tracking-tight text-foreground">
                                Three Pillars of Financial Integrity
                            </h2>
                        </div>
                        <p className="max-w-md text-xs sm:text-sm text-muted-foreground leading-relaxed">
                            Traditional bookkeeping relies on fallible
                            spreadsheets. EduFlow embeds mathematical rules
                            directly into disbursement workflows.
                        </p>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-3 gap-6 sm:gap-8">
                        {ESSAYS.map((essay) => (
                            <div
                                key={essay.numeral}
                                className="flex flex-col justify-between p-6 sm:p-7 rounded-xl border border-border/80 bg-card shadow-2xs hover:border-foreground/20 transition-colors"
                            >
                                <div>
                                    <div className="flex items-baseline justify-between border-b border-border/80 pb-4 font-mono text-xs text-muted-foreground">
                                        <span className="font-serif text-2xl font-bold text-foreground">
                                            {essay.numeral}
                                        </span>
                                        <span className="tracking-wider uppercase text-[10px]">
                                            {essay.kicker}
                                        </span>
                                    </div>

                                    <h3 className="mt-5 font-serif text-xl font-bold text-foreground">
                                        {essay.title}
                                    </h3>

                                    <p className="mt-3 text-xs sm:text-sm text-muted-foreground leading-relaxed">
                                        {essay.body}
                                    </p>
                                </div>

                                <div className="mt-8 pt-4 border-t border-border/80 flex items-center justify-between font-mono text-xs">
                                    <span className="text-muted-foreground">
                                        METRIC:
                                    </span>
                                    <span className="font-bold text-[#E30613]">
                                        {essay.statValue}
                                    </span>
                                </div>
                            </div>
                        ))}
                    </div>
                </section>

                {/* 3. SIMULATOR: Living Ledger Bench (Swiss Form + Modern Cards) */}
                <section
                    id="simulator"
                    className="flex flex-col gap-10 border-b border-border/80 pb-20 sm:pb-28"
                >
                    <div className="flex flex-col md:flex-row md:items-end justify-between gap-4">
                        <div>
                            <span className="font-mono text-xs uppercase tracking-widest text-[#E30613] block mb-1">
                                DISCOURSE 02 · INTERACTIVE POLICY BENCH
                            </span>
                            <h2 className="font-serif text-3xl sm:text-4xl font-bold tracking-tight text-foreground">
                                Test How EduFlow Handles Invoices
                            </h2>
                        </div>
                        <p className="max-w-md text-xs sm:text-sm text-muted-foreground leading-relaxed">
                            Select any pending payable below to observe how the
                            policy engine evaluates departmental ceilings and
                            guards your liquidity.
                        </p>
                    </div>

                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 sm:gap-8 items-start">
                        {/* List of items */}
                        <div className="lg:col-span-7 flex flex-col gap-3">
                            {PAYABLES.map((item) => {
                                const isSelected = selectedId === item.id;
                                return (
                                    <div
                                        key={item.id}
                                        role="button"
                                        tabIndex={0}
                                        onClick={() => {
                                            setSelectedId(item.id);
                                            setSimulatedPaid(false);
                                        }}
                                        onKeyDown={(e) => {
                                            if (
                                                e.key === 'Enter' ||
                                                e.key === ' '
                                            ) {
                                                e.preventDefault();
                                                setSelectedId(item.id);
                                                setSimulatedPaid(false);
                                            }
                                        }}
                                        className={`p-4 sm:p-5 rounded-lg border transition-all cursor-pointer text-left ${
                                            isSelected
                                                ? 'border-foreground bg-card shadow-sm ring-1 ring-foreground/10'
                                                : 'border-border/80 bg-card/60 hover:border-foreground/30'
                                        }`}
                                    >
                                        <div className="flex items-center justify-between gap-4">
                                            <div className="flex items-start gap-3">
                                                <div className="flex size-9 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40 text-foreground font-mono text-xs font-bold">
                                                    {item.category
                                                        .slice(0, 3)
                                                        .toUpperCase()}
                                                </div>
                                                <div>
                                                    <div className="flex items-center gap-2">
                                                        <span className="font-mono text-[11px] text-muted-foreground">
                                                            {item.code}
                                                        </span>
                                                        <span className="text-border">
                                                            ·
                                                        </span>
                                                        <span className="text-xs text-muted-foreground">
                                                            {item.vendor}
                                                        </span>
                                                    </div>
                                                    <span className="font-serif text-base font-bold text-foreground block mt-0.5">
                                                        {item.title}
                                                    </span>
                                                </div>
                                            </div>

                                            <div className="text-right shrink-0">
                                                <span className="font-mono text-base font-bold text-foreground block">
                                                    {item.amount}
                                                </span>
                                                <Badge
                                                    variant={item.badgeVariant}
                                                    className="font-mono text-[10px] uppercase tracking-wider mt-1"
                                                >
                                                    {item.verdict}
                                                </Badge>
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>

                        {/* Inspector card */}
                        <div className="lg:col-span-5">
                            <Card className="rounded-xl border border-border/90 bg-card p-6 shadow-xs flex flex-col justify-between h-full">
                                <CardHeader className="p-0 pb-4 border-b border-border/80 flex flex-row items-center justify-between">
                                    <span className="font-mono text-[11px] uppercase tracking-wider text-muted-foreground">
                                        EVALUATION REPORT · {activeItem.code}
                                    </span>
                                    <Badge
                                        variant={activeItem.badgeVariant}
                                        className="font-mono text-[10px] uppercase tracking-wider"
                                    >
                                        {activeItem.verdict}
                                    </Badge>
                                </CardHeader>

                                <CardContent className="p-0 pt-5 flex flex-col gap-4">
                                    <div>
                                        <span className="font-mono text-[11px] uppercase text-muted-foreground block">
                                            SELECTED REQUISITION
                                        </span>
                                        <span className="font-serif text-xl font-bold text-foreground block mt-1">
                                            {activeItem.title}
                                        </span>
                                        <span className="font-mono text-xs text-muted-foreground block mt-0.5">
                                            Sum: {activeItem.amount} (
                                            {activeItem.vendor})
                                        </span>
                                    </div>

                                    {/* Rationale explanation block */}
                                    <div className="rounded-md border border-border/80 bg-muted/30 p-3.5 text-xs text-muted-foreground leading-relaxed">
                                        <span className="font-semibold text-foreground block mb-1 font-mono text-[11px] uppercase">
                                            POLICY RATIONALE
                                        </span>
                                        {activeItem.editorialNote}
                                    </div>

                                    {/* Policy matrix tests */}
                                    <div className="flex flex-col gap-2 font-mono text-xs border-t border-border/80 pt-4 text-muted-foreground">
                                        <div className="flex justify-between">
                                            <span>AUTO PAY CAP ($50 MAX):</span>
                                            <span className="font-bold text-foreground">
                                                {activeItem.amountNumber <= 50
                                                    ? 'CONFORMANT ✓'
                                                    : 'EXCEEDED (HOLD)'}
                                            </span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span>
                                                EMERGENCY BUFFER ($5K FLOOR):
                                            </span>
                                            <span className="font-bold text-foreground">
                                                {activeItem.amountNumber === 200
                                                    ? 'AT RISK (PAUSED)'
                                                    : 'PRESERVED ✓'}
                                            </span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span>APPROVAL ACTION:</span>
                                            <span className="font-bold text-[#E30613]">
                                                {activeItem.amountNumber <= 50
                                                    ? 'AUTOMATIC DISBURSEMENT'
                                                    : activeItem.amountNumber ===
                                                        200
                                                      ? 'DISBURSEMENT PAUSED'
                                                      : 'MANAGER SIGN OFF REQUIRED'}
                                            </span>
                                        </div>
                                    </div>

                                    {/* Settlement trigger simulation */}
                                    <div className="mt-4 pt-4 border-t border-border/80 flex flex-col gap-2">
                                        <Button
                                            onClick={() =>
                                                setSimulatedPaid(true)
                                            }
                                            disabled={
                                                simulatedPaid ||
                                                activeItem.amountNumber === 200
                                            }
                                            className="w-full bg-foreground text-background hover:bg-foreground/90 font-mono text-xs font-semibold uppercase tracking-wider h-10 rounded-md"
                                        >
                                            {simulatedPaid ? (
                                                <>
                                                    <CheckCircle2 className="size-4 mr-2" />
                                                    <span>
                                                        VERIFIED SETTLEMENT
                                                        PROOF
                                                    </span>
                                                </>
                                            ) : activeItem.amountNumber ===
                                              200 ? (
                                                <span>
                                                    DISBURSEMENT BLOCKED BY
                                                    POLICY
                                                </span>
                                            ) : activeItem.amountNumber > 50 ? (
                                                <span>
                                                    SEND MANAGER PHONE ALERT
                                                </span>
                                            ) : (
                                                <span>
                                                    SIMULATE AUTOMATIC RELEASE
                                                </span>
                                            )}
                                        </Button>
                                        <span className="font-mono text-[10px] text-muted-foreground text-center">
                                            Deterministic code rules govern
                                            every balance move.
                                        </span>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                    </div>
                </section>

                {/* 4. CREED MANIFESTO: Typographic Poster Reveal */}
                <section
                    ref={creedRef}
                    className="flex flex-col items-start border-b border-border/80 pb-20 sm:pb-28"
                >
                    <span className="font-mono text-xs uppercase tracking-widest text-[#E30613] mb-6">
                        DISCOURSE 03 · THE EDUFLOW CREED
                    </span>

                    <div
                        style={{ textWrap: 'balance' }}
                        className="flex flex-wrap gap-x-3 sm:gap-x-4 gap-y-2 font-serif text-3xl sm:text-5xl lg:text-6xl font-bold tracking-tight leading-none text-foreground"
                    >
                        {EDITORIAL_CREED.map((word, index) => {
                            const isRevealed = index < revealedCount;
                            return (
                                <span
                                    key={word + index}
                                    className={`transition-opacity duration-150 ${
                                        isRevealed
                                            ? 'opacity-100 text-foreground'
                                            : 'opacity-20 text-muted-foreground'
                                    }`}
                                >
                                    {word}
                                </span>
                            );
                        })}
                    </div>

                    <p className="mt-8 max-w-xl text-xs sm:text-sm text-muted-foreground font-mono leading-relaxed uppercase">
                        Every institutional disbursement should occur with
                        mathematical certitude. Eliminate administrative
                        friction while preserving human governance.
                    </p>
                </section>

                {/* 5. MONOLITH CONVERSION ANCHOR: Broad Dark Broadsheet Sheet */}
                <section>
                    <div className="rounded-2xl border border-border bg-[#141517] text-[#F3F4F6] p-8 sm:p-16 shadow-lg">
                        <div className="grid grid-cols-1 lg:grid-cols-12 gap-8 items-end">
                            <div className="lg:col-span-8">
                                <span className="font-mono text-xs uppercase tracking-widest text-[#E30613] block mb-3">
                                    CAMPUS DEPLOYMENT
                                </span>
                                <h2
                                    style={{ textWrap: 'balance' }}
                                    className="font-serif text-3xl sm:text-5xl lg:text-6xl font-bold tracking-tight leading-[1.02]"
                                >
                                    Run Your School Treasury With Total Peace of
                                    Mind.
                                </h2>
                                <p
                                    style={{ textWrap: 'pretty' }}
                                    className="mt-5 max-w-xl text-sm sm:text-base text-neutral-400 leading-relaxed font-sans"
                                >
                                    Free and open source under the MIT license.
                                    No subscription bills. You retain absolute
                                    custody of institutional capital and oversee
                                    every dollar.
                                </p>
                            </div>

                            <div className="lg:col-span-4 flex flex-col gap-3">
                                <a href="/finance" className="w-full">
                                    <Button
                                        size="lg"
                                        className="w-full bg-[#E30613] text-white hover:bg-[#C20510] font-mono text-xs font-semibold uppercase tracking-wider h-11 rounded-md shadow-xs"
                                    >
                                        Launch Finance Console
                                        <ArrowRight className="size-3.5 ml-2" />
                                    </Button>
                                </a>
                                <Link href={login()} className="w-full">
                                    <Button
                                        variant="outline"
                                        size="lg"
                                        className="w-full border-neutral-700 bg-transparent text-neutral-200 hover:bg-neutral-800 font-mono text-xs font-semibold uppercase tracking-wider h-11 rounded-md"
                                    >
                                        Student Portal Login
                                    </Button>
                                </Link>
                            </div>
                        </div>

                        <div className="mt-10 pt-6 border-t border-neutral-800 font-mono text-xs text-neutral-400 flex flex-wrap gap-6 uppercase">
                            <span>Self Hosted Single Institution</span>
                            <span>·</span>
                            <span>MIT Open Source License</span>
                            <span>·</span>
                            <span>Zero Vendor Lock In</span>
                        </div>
                    </div>
                </section>
            </main>

            {/* Editorial Publication Colophon Footer */}
            <footer className="border-t border-border/80 bg-background py-10 font-mono text-xs text-muted-foreground uppercase">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 flex flex-col sm:flex-row items-center justify-between gap-6">
                    <div className="flex items-center gap-3">
                        <span className="size-2 bg-[#E30613]" />
                        <span className="font-serif text-sm font-bold normal-case text-foreground">
                            EduFlow
                        </span>
                        <span className="text-border">|</span>
                        <span>CH.8001 ZURICH SPECIFICATION</span>
                    </div>

                    <div className="flex flex-wrap items-center gap-6">
                        <a
                            href="https://github.com/koamishin/EduFlow"
                            target="_blank"
                            rel="noopener noreferrer"
                            className="hover:text-foreground transition-colors inline-flex items-center gap-1"
                        >
                            <span>Source Repository</span>
                            <ArrowUpRight className="size-3" />
                        </a>
                        <Link
                            href="/skills/design"
                            className="hover:text-foreground transition-colors"
                        >
                            Design Skills Hub
                        </Link>
                        <a
                            href="/finance"
                            className="hover:text-foreground transition-colors"
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
