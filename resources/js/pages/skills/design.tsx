import { useEffect, useRef, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    ArrowUpRight,
    Check,
    Code2,
    Copy,
    Cpu,
    ExternalLink,
    Eye,
    Layers,
    Layout,
    Palette,
    Shield,
    Sparkles,
    Terminal,
    Wand2,
    Zap,
} from 'lucide-react';

interface Preset {
    id: string;
    name: string;
    tone: string;
    displayFont: string;
    colorAccent: string;
    description: string;
    samplePrompt: string;
}

const PRESETS: Preset[] = [
    {
        id: 'editorial',
        name: 'Editorial Magazine',
        tone: 'Refined, high contrast, typography led',
        displayFont: 'Serif broadsheet headlines',
        colorAccent: 'Crimson and deep slate ink',
        description:
            'Broader margins, dramatic font scale contrast, column rules, and thoughtful reading rhythm.',
        samplePrompt:
            'Design an editorial research publication layout for financial security disclosures. Prioritize broadsheet typography, hairline dividers, tabular numbers, and zero generic cards.',
    },
    {
        id: 'brutalist',
        name: 'Raw Industrial',
        tone: 'Dense, utilitarian, uncompromising',
        displayFont: 'Monospace data stamps',
        colorAccent: 'Signal amber and graphite',
        description:
            'Thick borders, high information density, exposed metadata, and visible mechanical structure.',
        samplePrompt:
            'Create an institutional treasury monitor. Use dense data rows, monospace status indicators, visible boundary lines, and high contrast status indicators.',
    },
    {
        id: 'luxury',
        name: 'Luxury Restraint',
        tone: 'Minimal, spacious, velvet silence',
        displayFont: 'Spacious geometric sans',
        colorAccent: 'Warm alabaster and bronze',
        description:
            'Generous negative space, whisper borders, muted gold accents, and subtle micro interactions.',
        samplePrompt:
            'Build a private wealth portal for institutional endowments. Restrict color to alabaster and warm charcoal. Elevate typography and eliminate visual noise.',
    },
    {
        id: 'retro',
        name: 'Retro Futuristic',
        tone: 'CRT phosphors, early computing optimism',
        displayFont: 'Bitwise terminal headers',
        colorAccent: 'Phosphor green and obsidian',
        description:
            'Scanline textures, glowing status pills, mechanical toggles, and nostalgic computer interfaces.',
        samplePrompt:
            'Construct an on chain settlement inspector styled like an eighties mainframe terminal. Monospace readouts, physical toggle switches, and green phosphor highlights.',
    },
];

const FAQS = [
    {
        question: 'What do these two skills actually do inside an agent?',
        answer: 'They inject hard aesthetic constraints into the agent reasoning loop. The front end design skill stops generic purple gradients and predictable cards. The landing page skill enforces mathematical spacing tokens, zero hyphens in prose, nested corner radius formulas, and single action conversion architecture.',
    },
    {
        question: 'Which agent harnesses and environments support them?',
        answer: 'Both skills work in Antigravity, Claude Code, Cursor, OpenCode, and any tool that reads skill markdown files. Simply drop them into your project skills folder or load them into system prompts.',
    },
    {
        question:
            'Why does the landing page skill strictly forbid hyphens in text?',
        answer: 'Hyphenated prose often indicates rushed or robotic writing. Eliminating hyphens forces the model to craft cleaner sentences, improve cadence, and produce copy that reads with human conviction.',
    },
    {
        question:
            'How do the skills prevent generic artificial intelligence aesthetics?',
        answer: 'The skill instructions explicitly ban overused font families like Arial and Inter, purple gradients on white canvases, and cookie cutter card grids. Instead, the agent must commit to an extreme aesthetic register before generating code.',
    },
    {
        question: 'Does the generated code work with React and Tailwind CSS?',
        answer: 'Yes. Every generated snippet uses standard Tailwind utility classes, modern React components, semantic HTML elements, and accessible keyboard focus states.',
    },
    {
        question: 'What happens when my prompt conflicts with a visual rule?',
        answer: 'The visual system rules take priority over framework defaults. However, your explicit user instructions always win if you intentionally request a specific override.',
    },
    {
        question: 'Are these skills open source and free to use?',
        answer: 'Both skills are completely open source under the MIT license. You can inspect the rules, adapt them for your team, and share them across your repositories.',
    },
    {
        question: 'How does the nested corner radius formula work in code?',
        answer: 'When an element sits inside another card and the gap is under thirty two pixels, the inner radius equals the outer radius minus the gap. This keeps the corners concentric and visually unified.',
    },
];

const TAGLINE_WORDS = [
    'Bold',
    'maximalism',
    'and',
    'refined',
    'minimalism',
    'both',
    'succeed.',
    'The',
    'fatal',
    'mistake',
    'is',
    'generic',
    'mediocrity.',
];

export default function DesignSkillsLanding() {
    const [selectedPreset, setSelectedPreset] = useState<Preset>(PRESETS[0]);
    const [activeSkill, setActiveSkill] = useState<'frontend' | 'landing'>(
        'frontend',
    );
    const [copiedInstall, setCopiedInstall] = useState(false);
    const [copiedPrompt, setCopiedPrompt] = useState(false);
    const [revealedWordCount, setRevealedWordCount] = useState<number>(0);
    const taglineRef = useRef<HTMLDivElement>(null);

    const installCommand = 'npx skills add frontend-design landing-page-design';

    const handleCopyInstall = () => {
        navigator.clipboard.writeText(installCommand);
        setCopiedInstall(true);
        setTimeout(() => setCopiedInstall(false), 2000);
    };

    const handleCopyPrompt = () => {
        navigator.clipboard.writeText(selectedPreset.samplePrompt);
        setCopiedPrompt(true);
        setTimeout(() => setCopiedPrompt(false), 2000);
    };

    useEffect(() => {
        const observer = new IntersectionObserver(
            (entries) => {
                const [entry] = entries;
                if (entry.isIntersecting) {
                    let count = 0;
                    const interval = setInterval(() => {
                        count += 1;
                        setRevealedWordCount(count);
                        if (count >= TAGLINE_WORDS.length) {
                            clearInterval(interval);
                        }
                    }, 80);
                }
            },
            { threshold: 0.3 },
        );

        if (taglineRef.current) {
            observer.observe(taglineRef.current);
        }

        return () => observer.disconnect();
    }, []);

    return (
        <div className="min-h-screen bg-[#181818] text-[#F3F3F3] antialiased selection:bg-white selection:text-black">
            <Head title="Agent Design Skills — Distinctive Web Frontends Without Generic Slop" />

            {/* B7 Fluid Island Navigation Bar */}
            <nav className="fixed top-0 left-0 right-0 z-50 flex justify-center p-4">
                <div className="mt-2 flex w-max items-center justify-between gap-6 rounded-full border border-[#313131] bg-[#1F1F1F]/90 px-6 py-2.5 backdrop-blur-3xl shadow-lg transition-colors duration-200 ease-[cubic-bezier(0.32,0.72,0,1)]">
                    <Link
                        href="/"
                        className="flex items-center gap-2 text-sm font-semibold text-white"
                    >
                        <Sparkles className="size-4 text-[#F3F3F3]" />
                        <span>Design Skills</span>
                    </Link>

                    <div className="hidden sm:flex items-center gap-5 text-sm text-[#9B9B9B]">
                        <a
                            href="#manifesto"
                            className="hover:text-white transition-colors"
                        >
                            Manifesto
                        </a>
                        <a
                            href="#benefits"
                            className="hover:text-white transition-colors"
                        >
                            Benefits
                        </a>
                        <a
                            href="#workflow"
                            className="hover:text-white transition-colors"
                        >
                            Workflow
                        </a>
                        <a
                            href="#studio"
                            className="hover:text-white transition-colors"
                        >
                            Playground
                        </a>
                        <a
                            href="#faq"
                            className="hover:text-white transition-colors"
                        >
                            Questions
                        </a>
                    </div>

                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={handleCopyInstall}
                            className="flex items-center gap-1.5 rounded-full bg-white px-3.5 py-1.5 text-xs font-semibold text-black hover:bg-[#E5E5E5] active:scale-[0.98] transition-colors duration-200 ease-[cubic-bezier(0.32,0.72,0,1)]"
                        >
                            {copiedInstall ? (
                                <>
                                    <Check className="size-3.5 text-black" />
                                    <span>Copied Command</span>
                                </>
                            ) : (
                                <>
                                    <Terminal className="size-3.5" />
                                    <span>Install Skills</span>
                                </>
                            )}
                        </button>
                    </div>
                </div>
            </nav>

            <main className="flex flex-col gap-24 pt-32 pb-24 sm:gap-32">
                {/* A2 & B5 Hero Section */}
                <section className="mx-auto flex max-w-7xl flex-col items-center px-4 text-center sm:px-6 lg:px-8">
                    <div className="mb-4 inline-flex items-center gap-2 rounded-full border border-[#313131] bg-[#1F1F1F] px-3 py-1 text-xs text-[#9B9B9B]">
                        <span className="size-1.5 rounded-full bg-emerald-400" />
                        <span>Two Complementary Agent Skills</span>
                        <span className="text-[#313131]">|</span>
                        <span>Front End Design and Landing Page Design</span>
                    </div>

                    {/* B5 Gradient Heading: Left to right #FFFFFF -> #9B9B9B */}
                    <h1
                        style={{ textWrap: 'balance' }}
                        className="max-w-[680px] bg-gradient-to-r from-white to-[#9B9B9B] bg-clip-text text-3xl font-bold tracking-tight text-transparent sm:text-5xl lg:text-6xl"
                    >
                        Stop Shipping Generic AI Slop
                        <br />
                        Build Interfaces With Real Soul
                    </h1>

                    <p
                        style={{ textWrap: 'pretty' }}
                        className="mt-6 max-w-[680px] text-base text-[#9B9B9B] sm:text-lg leading-relaxed"
                    >
                        Hook your agent up with two rigorous design skills.
                        Eliminate purple gradients, ban vague copy, snap
                        typography to mathematical scales, and generate
                        distinctive production front ends.
                    </p>

                    {/* Single Primary Action + Risk Reversal */}
                    <div className="mt-8 flex flex-wrap items-center justify-center gap-3">
                        <button
                            type="button"
                            onClick={handleCopyInstall}
                            className="inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2.5 text-base font-semibold text-black hover:bg-[#E5E5E5] active:scale-[0.98] transition-colors duration-200 ease-[cubic-bezier(0.32,0.72,0,1)]"
                        >
                            <Terminal className="size-4" />
                            <span>Install Design Skills</span>
                        </button>

                        <a
                            href="#studio"
                            className="inline-flex items-center gap-2 rounded-lg border border-[#313131] bg-[#1F1F1F] px-4 py-2.5 text-base font-semibold text-white hover:bg-[#272727] active:scale-[0.98] transition-colors duration-200 ease-[cubic-bezier(0.32,0.72,0,1)]"
                        >
                            <span>Try Interactive Studio</span>
                            <ArrowRight className="size-4" />
                        </a>
                    </div>

                    {/* Proof Point */}
                    <div className="mt-6 flex items-center justify-center gap-6 text-xs text-[#9B9B9B]">
                        <div className="flex items-center gap-1.5">
                            <Check className="size-3.5 text-emerald-400" />
                            <span>Zero generic font fallbacks</span>
                        </div>
                        <div className="flex items-center gap-1.5">
                            <Check className="size-3.5 text-emerald-400" />
                            <span>Strict mathematical visual rules</span>
                        </div>
                        <div className="flex items-center gap-1.5">
                            <Check className="size-3.5 text-emerald-400" />
                            <span>One hundred percent open source</span>
                        </div>
                    </div>

                    {/* Hero Visual: Terminal Comparison Box */}
                    <div className="mt-12 w-full max-w-4xl rounded-2xl border border-[#313131] bg-[#1F1F1F] p-4 text-left shadow-2xl">
                        <div className="flex items-center justify-between border-b border-[#313131] pb-3 text-xs text-[#9B9B9B]">
                            <div className="flex items-center gap-2">
                                <span className="size-2.5 rounded-full bg-[#313131]" />
                                <span className="size-2.5 rounded-full bg-[#313131]" />
                                <span className="size-2.5 rounded-full bg-[#313131]" />
                                <span className="font-mono text-white ml-2">
                                    agent skill execution loop
                                </span>
                            </div>
                            <div className="flex items-center gap-2 font-mono">
                                <span className="text-emerald-400">ACTIVE</span>
                                <span>ANTIGRAVITY HARNESS</span>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 gap-4 pt-4 md:grid-cols-2">
                            {/* Generic Slop Column */}
                            <div className="rounded-xl border border-red-500/20 bg-red-950/10 p-4">
                                <div className="flex items-center justify-between text-xs font-semibold text-red-400">
                                    <span>UNGUARDED AGENT OUTPUT</span>
                                    <span>GENERIC SLOP</span>
                                </div>
                                <ul className="mt-3 flex flex-col gap-2 text-xs text-[#9B9B9B]">
                                    <li className="flex items-center gap-2">
                                        <span className="text-red-400">✕</span>
                                        <span>
                                            Defaults to Inter or Roboto
                                            everywhere
                                        </span>
                                    </li>
                                    <li className="flex items-center gap-2">
                                        <span className="text-red-400">✕</span>
                                        <span>
                                            Predictable purple gradient on light
                                            background
                                        </span>
                                    </li>
                                    <li className="flex items-center gap-2">
                                        <span className="text-red-400">✕</span>
                                        <span>
                                            Vague copy: streamline, elevate,
                                            unleash
                                        </span>
                                    </li>
                                    <li className="flex items-center gap-2">
                                        <span className="text-red-400">✕</span>
                                        <span>
                                            Arbitrary font sizes and mismatched
                                            radii
                                        </span>
                                    </li>
                                </ul>
                            </div>

                            {/* Skills Empowered Column */}
                            <div className="rounded-xl border border-emerald-500/30 bg-emerald-950/10 p-4">
                                <div className="flex items-center justify-between text-xs font-semibold text-emerald-400">
                                    <span>WITH FRONTEND & LANDING SKILLS</span>
                                    <span>INTENTIONAL DESIGN</span>
                                </div>
                                <ul className="mt-3 flex flex-col gap-2 text-xs text-[#9B9B9B]">
                                    <li className="flex items-center gap-2">
                                        <span className="text-emerald-400">
                                            ✓
                                        </span>
                                        <span className="text-white">
                                            Commits to bold aesthetic direction
                                            first
                                        </span>
                                    </li>
                                    <li className="flex items-center gap-2">
                                        <span className="text-emerald-400">
                                            ✓
                                        </span>
                                        <span className="text-white">
                                            Strict typography scale and zero
                                            hyphens in prose
                                        </span>
                                    </li>
                                    <li className="flex items-center gap-2">
                                        <span className="text-emerald-400">
                                            ✓
                                        </span>
                                        <span className="text-white">
                                            Nested corner radii formula: inner
                                            equals outer minus gap
                                        </span>
                                    </li>
                                    <li className="flex items-center gap-2">
                                        <span className="text-emerald-400">
                                            ✓
                                        </span>
                                        <span className="text-white">
                                            One offer, one audience, single
                                            primary action
                                        </span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </section>

                {/* B11 Mandatory Tagline Reveal Section */}
                <section
                    id="manifesto"
                    ref={taglineRef}
                    className="mx-auto flex max-w-5xl flex-col items-center justify-center px-4 py-16 text-center sm:px-6 lg:px-8"
                >
                    <span className="text-xs font-semibold uppercase tracking-widest text-[#9B9B9B]">
                        THE CORE MANDATE
                    </span>

                    <div
                        style={{ textWrap: 'balance' }}
                        className="mt-6 flex flex-wrap justify-center gap-x-3 gap-y-2 text-3xl font-bold leading-tight sm:text-5xl lg:text-6xl"
                    >
                        {TAGLINE_WORDS.map((word, index) => {
                            const isRevealed = index < revealedWordCount;
                            return (
                                <span
                                    key={word + index}
                                    className={`transition-opacity duration-200 ease-[cubic-bezier(0.32,0.72,0,1)] ${
                                        isRevealed
                                            ? 'text-white opacity-100'
                                            : 'text-[#9B9B9B]/25 opacity-30'
                                    }`}
                                >
                                    {word}
                                </span>
                            );
                        })}
                    </div>

                    <p
                        style={{ textWrap: 'pretty' }}
                        className="mt-8 max-w-[680px] text-sm text-[#9B9B9B] leading-relaxed"
                    >
                        Every interface generated by an artificial intelligence
                        model should exhibit intentional taste. Choose an
                        extreme aesthetic and execute with mathematical
                        restraint.
                    </p>
                </section>

                {/* A4 & A5 Benefits Section (Outcome Driven) */}
                <section
                    id="benefits"
                    className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8"
                >
                    <div className="mb-12 flex flex-col items-center text-center">
                        <span className="text-xs font-semibold uppercase tracking-widest text-[#9B9B9B]">
                            OUTCOME DRIVEN ADVANTAGES
                        </span>
                        <h2
                            style={{ textWrap: 'balance' }}
                            className="mt-2 text-2xl font-bold tracking-tight text-white sm:text-4xl"
                        >
                            Four Reasons to Install These Skills Today
                        </h2>
                        <p
                            style={{ textWrap: 'pretty' }}
                            className="mt-4 max-w-[680px] text-sm text-[#9B9B9B]"
                        >
                            Equip your agent with rules that enforce commercial
                            clarity, rigorous layout math, and unforgettable
                            aesthetics.
                        </p>
                    </div>

                    <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                        {/* Benefit 1 */}
                        <div className="rounded-2xl border border-[#313131] bg-[#1F1F1F] p-8 transition-colors duration-200 ease-[cubic-bezier(0.32,0.72,0,1)] hover:border-[#4B4B4B]">
                            <div className="flex size-10 items-center justify-center rounded-xl bg-white/10 text-white">
                                <Palette className="size-5" />
                            </div>
                            <h3 className="mt-4 text-xl font-bold text-white">
                                Radical Aesthetic Direction
                            </h3>
                            <p className="mt-2 text-sm text-[#9B9B9B] leading-relaxed">
                                Never converge on the same predictable layout.
                                The skill forces the agent to commit to an
                                aesthetic register, whether brutalist raw,
                                minimal editorial, or retro futuristic, creating
                                memorable user experiences.
                            </p>
                            <div className="mt-4 border-t border-[#313131] pt-4 text-xs font-mono text-[#9B9B9B]">
                                Rule: Avoid common font choices and cookie
                                cutter templates
                            </div>
                        </div>

                        {/* Benefit 2 */}
                        <div className="rounded-2xl border border-[#313131] bg-[#1F1F1F] p-8 transition-colors duration-200 ease-[cubic-bezier(0.32,0.72,0,1)] hover:border-[#4B4B4B]">
                            <div className="flex size-10 items-center justify-center rounded-xl bg-white/10 text-white">
                                <Layout className="size-5" />
                            </div>
                            <h3 className="mt-4 text-xl font-bold text-white">
                                Mathematical Visual System
                            </h3>
                            <p className="mt-2 text-sm text-[#9B9B9B] leading-relaxed">
                                Every font size snaps to the Tailwind typography
                                scale. Every corner radius follows concentric
                                nesting geometry. No arbitrary pixel sizes or
                                random spacing values pollute your codebase.
                            </p>
                            <div className="mt-4 border-t border-[#313131] pt-4 text-xs font-mono text-[#9B9B9B]">
                                Formula: Inner radius equals outer radius minus
                                gap
                            </div>
                        </div>

                        {/* Benefit 3 */}
                        <div className="rounded-2xl border border-[#313131] bg-[#1F1F1F] p-8 transition-colors duration-200 ease-[cubic-bezier(0.32,0.72,0,1)] hover:border-[#4B4B4B]">
                            <div className="flex size-10 items-center justify-center rounded-xl bg-white/10 text-white">
                                <Zap className="size-5" />
                            </div>
                            <h3 className="mt-4 text-xl font-bold text-white">
                                Conversion Architecture
                            </h3>
                            <p className="mt-2 text-sm text-[#9B9B9B] leading-relaxed">
                                A landing page wins one intent. The skill
                                enforces single primary call to action
                                structure, specific proof points next to claims,
                                structured objection handling, and clear risk
                                reversals.
                            </p>
                            <div className="mt-4 border-t border-[#313131] pt-4 text-xs font-mono text-[#9B9B9B]">
                                Architecture: One offer, one audience, single
                                primary action
                            </div>
                        </div>

                        {/* Benefit 4 */}
                        <div className="rounded-2xl border border-[#313131] bg-[#1F1F1F] p-8 transition-colors duration-200 ease-[cubic-bezier(0.32,0.72,0,1)] hover:border-[#4B4B4B]">
                            <div className="flex size-10 items-center justify-center rounded-xl bg-white/10 text-white">
                                <Shield className="size-5" />
                            </div>
                            <h3 className="mt-4 text-xl font-bold text-white">
                                Production Grade Code Output
                            </h3>
                            <p className="mt-2 text-sm text-[#9B9B9B] leading-relaxed">
                                Real accessible HTML structure, no text hyphens,
                                balanced headings, zero orphaned words, and
                                custom cubic bezier easing curves make generated
                                interfaces immediately shippable.
                            </p>
                            <div className="mt-4 border-t border-[#313131] pt-4 text-xs font-mono text-[#9B9B9B]">
                                Physics: Custom cubic bezier mass physics easing
                            </div>
                        </div>
                    </div>
                </section>

                {/* How It Works Section (3 Steps) */}
                <section
                    id="workflow"
                    className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8"
                >
                    <div className="mb-12 flex flex-col items-center text-center">
                        <span className="text-xs font-semibold uppercase tracking-widest text-[#9B9B9B]">
                            SETUP PROCESS
                        </span>
                        <h2
                            style={{ textWrap: 'balance' }}
                            className="mt-2 text-2xl font-bold tracking-tight text-white sm:text-4xl"
                        >
                            How It Works in Three Simple Steps
                        </h2>
                    </div>

                    <div className="grid grid-cols-1 gap-6 md:grid-cols-3">
                        <div className="rounded-2xl border border-[#313131] bg-[#1F1F1F] p-6">
                            <span className="text-2xl font-bold font-mono text-white">
                                01
                            </span>
                            <h3 className="mt-3 text-lg font-bold text-white">
                                Place Skills in Workspace
                            </h3>
                            <p className="mt-2 text-xs text-[#9B9B9B] leading-relaxed">
                                Add the markdown skills to your project folder
                                under skills or your agent harness
                                configuration. The agent discovers them
                                automatically.
                            </p>
                        </div>

                        <div className="rounded-2xl border border-[#313131] bg-[#1F1F1F] p-6">
                            <span className="text-2xl font-bold font-mono text-white">
                                02
                            </span>
                            <h3 className="mt-3 text-lg font-bold text-white">
                                Provide Context and Tone
                            </h3>
                            <p className="mt-2 text-xs text-[#9B9B9B] leading-relaxed">
                                Tell the agent your product purpose, target
                                audience, and chosen aesthetic direction. The
                                agent checks visual guidelines before writing
                                code.
                            </p>
                        </div>

                        <div className="rounded-2xl border border-[#313131] bg-[#1F1F1F] p-6">
                            <span className="text-2xl font-bold font-mono text-white">
                                03
                            </span>
                            <h3 className="mt-3 text-lg font-bold text-white">
                                Receive Production Interfaces
                            </h3>
                            <p className="mt-2 text-xs text-[#9B9B9B] leading-relaxed">
                                The agent outputs real working React, Tailwind,
                                and accessible components crafted with
                                distinctive typography and mathematical
                                restraint.
                            </p>
                        </div>
                    </div>
                </section>

                {/* Interactive Studio & Playground */}
                <section
                    id="studio"
                    className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8"
                >
                    <div className="mb-10 flex flex-col items-center text-center">
                        <span className="text-xs font-semibold uppercase tracking-widest text-[#9B9B9B]">
                            INTERACTIVE PLAYGROUND
                        </span>
                        <h2
                            style={{ textWrap: 'balance' }}
                            className="mt-2 text-2xl font-bold tracking-tight text-white sm:text-4xl"
                        >
                            Test the Aesthetic Engine
                        </h2>
                        <p
                            style={{ textWrap: 'pretty' }}
                            className="mt-3 max-w-[680px] text-sm text-[#9B9B9B]"
                        >
                            Select a design flavor below to preview the creative
                            direction and inspect prompt snippets ready for your
                            agent.
                        </p>
                    </div>

                    <div className="rounded-2xl border border-[#313131] bg-[#1F1F1F] p-6 shadow-2xl">
                        {/* Skill Selector Tabs */}
                        <div className="flex flex-wrap items-center justify-between gap-4 border-b border-[#313131] pb-4">
                            <div className="flex items-center gap-2">
                                <button
                                    type="button"
                                    onClick={() => setActiveSkill('frontend')}
                                    className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors duration-200 ease-[cubic-bezier(0.32,0.72,0,1)] ${
                                        activeSkill === 'frontend'
                                            ? 'bg-white text-black'
                                            : 'border border-[#313131] bg-transparent text-[#9B9B9B] hover:text-white'
                                    }`}
                                >
                                    frontend design skill
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setActiveSkill('landing')}
                                    className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors duration-200 ease-[cubic-bezier(0.32,0.72,0,1)] ${
                                        activeSkill === 'landing'
                                            ? 'bg-white text-black'
                                            : 'border border-[#313131] bg-transparent text-[#9B9B9B] hover:text-white'
                                    }`}
                                >
                                    landing page design skill
                                </button>
                            </div>

                            <span className="text-xs font-mono text-[#9B9B9B]">
                                ACTIVE FLAVOR:{' '}
                                {selectedPreset.name.toUpperCase()}
                            </span>
                        </div>

                        {/* Preset Buttons Grid */}
                        <div className="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
                            {PRESETS.map((preset) => (
                                <button
                                    key={preset.id}
                                    type="button"
                                    onClick={() => setSelectedPreset(preset)}
                                    className={`rounded-xl border p-3 text-left transition-colors duration-200 ease-[cubic-bezier(0.32,0.72,0,1)] ${
                                        selectedPreset.id === preset.id
                                            ? 'border-white bg-[#272727]'
                                            : 'border-[#313131] bg-[#181818] hover:border-[#4B4B4B]'
                                    }`}
                                >
                                    <div className="text-xs font-bold text-white">
                                        {preset.name}
                                    </div>
                                    <div className="text-[11px] text-[#9B9B9B] mt-0.5 truncate">
                                        {preset.tone}
                                    </div>
                                </button>
                            ))}
                        </div>

                        {/* Preset Inspection Panel */}
                        <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-12">
                            <div className="flex flex-col gap-4 lg:col-span-5">
                                <div className="rounded-xl border border-[#313131] bg-[#181818] p-4 text-xs flex flex-col gap-3">
                                    <div className="flex justify-between border-b border-[#313131] pb-2">
                                        <span className="text-[#9B9B9B]">
                                            Aesthetic Register:
                                        </span>
                                        <span className="font-semibold text-white">
                                            {selectedPreset.name}
                                        </span>
                                    </div>
                                    <div className="flex justify-between border-b border-[#313131] pb-2">
                                        <span className="text-[#9B9B9B]">
                                            Typography Intent:
                                        </span>
                                        <span className="text-white">
                                            {selectedPreset.displayFont}
                                        </span>
                                    </div>
                                    <div className="flex justify-between border-b border-[#313131] pb-2">
                                        <span className="text-[#9B9B9B]">
                                            Palette Contrast:
                                        </span>
                                        <span className="text-white">
                                            {selectedPreset.colorAccent}
                                        </span>
                                    </div>
                                    <p className="text-[#9B9B9B] leading-relaxed pt-1">
                                        {selectedPreset.description}
                                    </p>
                                </div>

                                <div className="rounded-xl border border-[#313131] bg-[#181818] p-4 text-xs flex flex-col gap-2">
                                    <span className="font-semibold text-white">
                                        Skill Rules Applied:
                                    </span>
                                    <div className="flex flex-wrap gap-1.5 text-[11px] text-[#9B9B9B]">
                                        <span className="rounded bg-[#272727] px-2 py-0.5">
                                            no hyphens in copy
                                        </span>
                                        <span className="rounded bg-[#272727] px-2 py-0.5">
                                            nested radii math
                                        </span>
                                        <span className="rounded bg-[#272727] px-2 py-0.5">
                                            B1 scale snapping
                                        </span>
                                        <span className="rounded bg-[#272727] px-2 py-0.5">
                                            custom easing
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div className="flex flex-col justify-between rounded-xl border border-[#313131] bg-[#181818] p-4 lg:col-span-7">
                                <div>
                                    <div className="flex items-center justify-between border-b border-[#313131] pb-2 text-xs">
                                        <span className="font-mono text-white">
                                            READY TO USE AGENT PROMPT
                                        </span>
                                        <button
                                            type="button"
                                            onClick={handleCopyPrompt}
                                            className="flex items-center gap-1 text-[#9B9B9B] hover:text-white transition-colors"
                                        >
                                            {copiedPrompt ? (
                                                <>
                                                    <Check className="size-3.5 text-emerald-400" />
                                                    <span>Copied</span>
                                                </>
                                            ) : (
                                                <>
                                                    <Copy className="size-3.5" />
                                                    <span>Copy Prompt</span>
                                                </>
                                            )}
                                        </button>
                                    </div>

                                    <div className="mt-4 font-mono text-xs text-[#E5E5E5] leading-relaxed bg-[#131209] p-4 rounded-lg border border-[#313131]">
                                        {selectedPreset.samplePrompt}
                                    </div>
                                </div>

                                <div className="mt-4 flex items-center justify-between text-xs text-[#9B9B9B] pt-2 border-t border-[#313131]">
                                    <span>
                                        Pass this directly to your coding agent
                                        session
                                    </span>
                                    <button
                                        type="button"
                                        onClick={handleCopyPrompt}
                                        className="font-semibold text-white hover:underline"
                                    >
                                        Copy to Clipboard →
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                {/* FAQ Section (8 Questions) */}
                <section
                    id="faq"
                    className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8"
                >
                    <div className="mb-12 flex flex-col items-center text-center">
                        <span className="text-xs font-semibold uppercase tracking-widest text-[#9B9B9B]">
                            COMMON QUESTIONS
                        </span>
                        <h2
                            style={{ textWrap: 'balance' }}
                            className="mt-2 text-2xl font-bold tracking-tight text-white sm:text-4xl"
                        >
                            Frequently Asked Questions
                        </h2>
                    </div>

                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        {FAQS.map((faq) => (
                            <div
                                key={faq.question}
                                className="rounded-2xl border border-[#313131] bg-[#1F1F1F] p-6 text-left"
                            >
                                <h3 className="text-base font-bold text-white">
                                    {faq.question}
                                </h3>
                                <p className="mt-2 text-xs text-[#9B9B9B] leading-relaxed">
                                    {faq.answer}
                                </p>
                            </div>
                        ))}
                    </div>
                </section>

                {/* Risk Reversal & Final Call to Action */}
                <section className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="rounded-3xl border border-[#313131] bg-[#1F1F1F] p-8 text-center sm:p-16">
                        <span className="text-xs font-semibold uppercase tracking-widest text-[#9B9B9B]">
                            ELEVATE YOUR CODE TODAY
                        </span>

                        <h2
                            style={{ textWrap: 'balance' }}
                            className="mx-auto mt-3 max-w-[680px] text-3xl font-bold text-white sm:text-5xl"
                        >
                            Build Distinctive User Interfaces Without The
                            Generic Slop
                        </h2>

                        <p
                            style={{ textWrap: 'pretty' }}
                            className="mx-auto mt-4 max-w-[680px] text-sm text-[#9B9B9B]"
                        >
                            Free and open source under the MIT license. Add both
                            skills to your agent directory in seconds and start
                            shipping interfaces with intentional personality.
                        </p>

                        <div className="mt-8 flex flex-wrap items-center justify-center gap-4">
                            <button
                                type="button"
                                onClick={handleCopyInstall}
                                className="inline-flex items-center gap-2 rounded-lg bg-white px-5 py-3 text-base font-semibold text-black hover:bg-[#E5E5E5] active:scale-[0.98] transition-colors duration-200 ease-[cubic-bezier(0.32,0.72,0,1)]"
                            >
                                <Terminal className="size-4" />
                                <span>Copy Installation Command</span>
                            </button>

                            <Link
                                href="/"
                                className="inline-flex items-center gap-2 rounded-lg border border-[#313131] bg-[#181818] px-5 py-3 text-base font-semibold text-white hover:bg-[#272727] active:scale-[0.98] transition-colors duration-200 ease-[cubic-bezier(0.32,0.72,0,1)]"
                            >
                                <span>Return to EduFlow Home</span>
                                <ArrowRight className="size-4" />
                            </Link>
                        </div>
                    </div>
                </section>
            </main>

            {/* Semantic Footer */}
            <footer className="border-t border-[#313131] bg-[#131209] py-12 text-xs text-[#9B9B9B]">
                <div className="mx-auto flex max-w-7xl flex-col items-center justify-between gap-6 px-4 sm:flex-row sm:px-6 lg:px-8">
                    <div className="flex flex-col gap-1 text-center sm:text-left">
                        <span className="font-semibold text-white">
                            Agent Design Skills Hub
                        </span>
                        <span>
                            Open source tooling for high converting interfaces
                            and front end design.
                        </span>
                    </div>

                    <div className="flex items-center gap-6">
                        <Link
                            href="/"
                            className="hover:text-white transition-colors"
                        >
                            EduFlow App
                        </Link>
                        <a
                            href="https://github.com/koamishin/EduFlow"
                            target="_blank"
                            rel="noopener noreferrer"
                            className="hover:text-white transition-colors inline-flex items-center gap-1"
                        >
                            <span>Source Repository</span>
                            <ExternalLink className="size-3" />
                        </a>
                        <span className="text-[#313131]">|</span>
                        <span>MIT License</span>
                    </div>
                </div>
            </footer>
        </div>
    );
}
