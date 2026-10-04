import { useRef, useState, useTransition } from 'react';
import {
    ArrowRight,
    Bot,
    HelpCircle,
    Lock,
    Send,
    ShieldCheck,
    Sparkles,
    User,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import type { AskEduFlowQueryResponse } from '@/types/financial-aid';

interface ChatMessage {
    id: string;
    sender: 'user' | 'assistant';
    text: string;
    topic?: string;
    suggestedFollowups?: string[];
    timestamp: string;
}

interface AskEduFlowProps {
    suggestedQuestions?: string[];
    displayCurrency?: string;
    rateDescription?: string;
    className?: string;
}

export function AskEduFlow({
    suggestedQuestions = [
        'Why was my 150 USDC request split?',
        'What is my tuition balance in PHP?',
        'What are the assistance guidelines?',
        'How does currency rate locking work?',
    ],
    displayCurrency = 'PHP',
    rateDescription,
    className,
}: AskEduFlowProps) {
    const [question, setQuestion] = useState('');
    const [isPending, startTransition] = useTransition();
    const [messages, setMessages] = useState<ChatMessage[]>([]);
    const [error, setError] = useState<string | null>(null);
    // Server-side thread, so a follow-up has the earlier turns as context.
    const conversationId = useRef<string | null>(null);
    const [lastSource, setLastSource] = useState<
        'deterministic' | 'assistant' | null
    >(null);

    const ask = async (queryText: string) => {
        const trimmed = queryText.trim();
        if (!trimmed || isPending) return;

        setError(null);
        const userMsg: ChatMessage = {
            id: 'user-' + Date.now(),
            sender: 'user',
            text: trimmed,
            timestamp: new Date().toLocaleTimeString([], {
                hour: '2-digit',
                minute: '2-digit',
            }),
        };

        setMessages((prev) => [...prev, userMsg]);
        setQuestion('');

        startTransition(async () => {
            try {
                const csrfToken =
                    (
                        document.querySelector(
                            'meta[name="csrf-token"]',
                        ) as HTMLMetaElement
                    )?.content || '';

                const response = await fetch('/student/ask-eduflow', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        question: trimmed,
                        conversation_id: conversationId.current,
                    }),
                });

                if (!response.ok) {
                    const data = await response.json().catch(() => null);
                    throw new Error(
                        data?.message ||
                            `Request failed with status ${response.status}`,
                    );
                }

                const json = await response.json();
                const result: AskEduFlowQueryResponse = json.data;

                // Trust the server's thread id over our own guess: it is null
                // when the previous thread belonged to someone else, and the
                // server has already refused to continue it.
                conversationId.current = result.conversation_id;
                setLastSource(result.source);

                const botMsg: ChatMessage = {
                    id: 'bot-' + Date.now(),
                    sender: 'assistant',
                    text: result.answer,
                    topic: result.topic,
                    suggestedFollowups: result.suggestedFollowups,
                    timestamp: new Date(result.answered_at).toLocaleTimeString(
                        [],
                        { hour: '2-digit', minute: '2-digit' },
                    ),
                };

                setMessages((prev) => [...prev, botMsg]);
            } catch (err: unknown) {
                const msg =
                    err instanceof Error
                        ? err.message
                        : 'Could not connect to EduFlow AI. Please try again.';
                setError(msg);
            }
        });
    };

    const handleFormSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        ask(question);
    };

    return (
        <Card
            className={`overflow-hidden border-amber-200/60 bg-gradient-to-b from-card via-card to-amber-50/20 dark:border-amber-950/40 dark:to-amber-950/10 ${className ?? ''}`}
        >
            <CardHeader className="gap-2 border-b bg-muted/20 pb-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex items-center gap-2">
                        <div className="flex size-8 items-center justify-center rounded-lg bg-amber-500/10 text-amber-600 dark:bg-amber-500/20 dark:text-amber-400">
                            <Sparkles className="size-4" />
                        </div>
                        <div>
                            <CardTitle className="text-base font-semibold">
                                Ask EduFlow AI
                            </CardTitle>
                            <CardDescription className="text-xs">
                                Natural-language explainability for tuition,
                                rates, and assistance decisions.
                            </CardDescription>
                        </div>
                    </div>
                    <div className="flex items-center gap-1.5">
                        <Badge
                            variant="outline"
                            className="border-amber-300 bg-amber-100/50 text-[11px] text-amber-800 dark:border-amber-800 dark:bg-amber-950/50 dark:text-amber-300"
                        >
                            <Lock className="mr-1 size-3" />
                            Deterministic AI · Zero Direct Fund Control
                        </Badge>
                        {lastSource === 'assistant' && (
                            <Badge
                                variant="outline"
                                className="border-emerald-300 bg-emerald-100/50 text-[11px] text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300"
                            >
                                <ShieldCheck className="mr-1 size-3" />
                                Model phrasing · figures verified
                            </Badge>
                        )}
                    </div>
                </div>

                {rateDescription && (
                    <div className="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground">
                        <ShieldCheck className="size-3.5 text-emerald-600 dark:text-emerald-400" />
                        <span>Live locked rate: {rateDescription}</span>
                    </div>
                )}
            </CardHeader>

            <CardContent className="space-y-4 pt-5">
                {/* Conversation History */}
                {messages.length === 0 ? (
                    <div className="space-y-3 py-2">
                        <p className="text-xs font-medium text-muted-foreground">
                            Suggested questions students ask:
                        </p>
                        <div className="flex flex-wrap gap-2">
                            {suggestedQuestions.map((q) => (
                                <button
                                    key={q}
                                    type="button"
                                    onClick={() => ask(q)}
                                    disabled={isPending}
                                    className="group inline-flex items-center gap-1.5 rounded-full border bg-background px-3 py-1.5 text-xs font-medium text-foreground/80 shadow-xs transition hover:border-amber-400 hover:bg-amber-50 hover:text-amber-900 focus-visible:outline-2 focus-visible:outline-ring dark:hover:bg-amber-950/40 dark:hover:text-amber-200"
                                >
                                    <HelpCircle className="size-3 text-muted-foreground group-hover:text-amber-600" />
                                    <span>{q}</span>
                                    <ArrowRight className="size-3 opacity-0 transition group-hover:opacity-100" />
                                </button>
                            ))}
                        </div>
                    </div>
                ) : (
                    <div className="space-y-3.5 max-h-96 overflow-y-auto pr-1">
                        {messages.map((m) => (
                            <div
                                key={m.id}
                                className={`flex gap-3 text-sm ${
                                    m.sender === 'user'
                                        ? 'justify-end'
                                        : 'justify-start'
                                }`}
                            >
                                {m.sender === 'assistant' && (
                                    <div className="flex size-7 shrink-0 items-center justify-center rounded-full bg-amber-500/10 text-amber-600 dark:bg-amber-500/20 dark:text-amber-400">
                                        <Bot className="size-4" />
                                    </div>
                                )}
                                <div
                                    className={`max-w-[85%] rounded-2xl px-4 py-2.5 text-xs leading-relaxed sm:text-sm ${
                                        m.sender === 'user'
                                            ? 'bg-primary text-primary-foreground'
                                            : 'border bg-card text-card-foreground shadow-xs'
                                    }`}
                                >
                                    <p className="whitespace-pre-wrap">
                                        {m.text}
                                    </p>
                                    <span
                                        className={`mt-1 block text-[10px] ${
                                            m.sender === 'user'
                                                ? 'text-primary-foreground/70'
                                                : 'text-muted-foreground'
                                        }`}
                                    >
                                        {m.timestamp}
                                    </span>

                                    {/* Followup suggestions on the latest assistant answer */}
                                    {m.suggestedFollowups &&
                                        m.suggestedFollowups.length > 0 && (
                                            <div className="mt-2.5 border-t border-border/50 pt-2">
                                                <p className="mb-1.5 text-[11px] font-medium text-muted-foreground">
                                                    Related questions:
                                                </p>
                                                <div className="flex flex-wrap gap-1.5">
                                                    {m.suggestedFollowups.map(
                                                        (follow) => (
                                                            <button
                                                                key={follow}
                                                                type="button"
                                                                onClick={() =>
                                                                    ask(follow)
                                                                }
                                                                className="rounded-md border bg-muted/40 px-2 py-0.5 text-[11px] transition hover:bg-muted"
                                                            >
                                                                {follow}
                                                            </button>
                                                        ),
                                                    )}
                                                </div>
                                            </div>
                                        )}
                                </div>
                                {m.sender === 'user' && (
                                    <div className="flex size-7 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                                        <User className="size-4" />
                                    </div>
                                )}
                            </div>
                        ))}

                        {isPending && (
                            <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                <Spinner className="size-3.5" />
                                <span>
                                    Checking your record, policy and locked
                                    rates...
                                </span>
                            </div>
                        )}
                    </div>
                )}

                {error && (
                    <div className="rounded-lg border border-destructive/30 bg-destructive/10 p-2.5 text-xs text-destructive">
                        {error}
                    </div>
                )}
            </CardContent>

            <CardFooter className="border-t bg-muted/10 p-3">
                <form
                    onSubmit={handleFormSubmit}
                    className="flex w-full items-center gap-2"
                >
                    <Input
                        value={question}
                        onChange={(e) => setQuestion(e.target.value)}
                        placeholder={`Ask anything about your ${displayCurrency} tuition, rates, or assistance...`}
                        disabled={isPending}
                        className="h-9 text-xs sm:text-sm"
                    />
                    <Button
                        type="submit"
                        size="sm"
                        disabled={!question.trim() || isPending}
                        className="h-9 shrink-0 gap-1.5 bg-amber-600 px-3 text-white hover:bg-amber-700 dark:bg-amber-600"
                    >
                        {isPending ? (
                            <Spinner className="size-3.5" />
                        ) : (
                            <Send className="size-3.5" />
                        )}
                        <span className="hidden sm:inline">Ask AI</span>
                    </Button>
                </form>
            </CardFooter>
        </Card>
    );
}
