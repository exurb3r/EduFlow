<div
    x-data="adminAiChat({
        csrfToken: '{{ csrf_token() }}',
        cycleUrl: null,
        cycleAvailable: false,
        manualChatEnabled: {{ Js::from($manualChatEnabled) }},
        providerCallsAllowed: {{ Js::from($providerCallsAllowed) }},
        provider: '{{ $provider }}',
        providerLabel: '{{ $providerLabel }}',
        modelName: '{{ $modelName }}',
        workspace: {{ Js::from($workspace) }},
        adminUser: {{ Js::from($adminUser) }},
        initialSessions: {{ Js::from($initialSessions) }},
        initialActiveSession: {{ Js::from($initialActiveSession) }},
    })"
    class="arc-shell relative flex h-dvh w-full overflow-hidden antialiased font-sans"
>
    <div class="sr-only" aria-live="polite" x-text="isStreaming ? 'ARC is responding' : (aiActions.some(a => a.status === 'pending') ? 'An action is waiting for your approval.' : '')"></div>

    {{-- Left Sidebar: Conversations & History -------------------------------- --}}
    <aside
        :class="sidebarOpen ? 'w-72 translate-x-0' : 'w-0 -translate-x-full md:w-0 md:translate-x-0'"
        class="arc-sidebar absolute inset-y-0 start-0 z-40 flex flex-col border-r transition-all duration-200 ease-in-out md:static md:z-auto"
        style="min-width: 0;"
    >
        <div x-show="sidebarOpen" class="flex h-full flex-col p-3" style="width: 18rem;">
            <p class="mb-2 px-2 text-[10px] font-medium uppercase tracking-wider text-zinc-500">Manual conversations</p>
            {{-- Sidebar Header & New Chat button --}}
            <div class="mb-2 flex items-center justify-between gap-1.5">
                <button
                    type="button"
                    @click="newChat()"
                    :disabled="!manualChatEnabled"
                    class="disabled:cursor-not-allowed disabled:opacity-50 flex flex-1 items-center justify-between rounded-xl border border-zinc-200/90 bg-white px-3 py-2 text-xs font-medium text-zinc-800 shadow-2xs transition hover:border-zinc-300 hover:bg-zinc-50 active:scale-[0.99] dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:border-zinc-700 dark:hover:bg-zinc-800"
                >
                    <span class="flex items-center gap-2">
                        <svg class="h-3.5 w-3.5 text-zinc-500 dark:text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                        </svg>
                        <span>New Chat</span>
                    </span>
                    <span class="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] font-mono text-zinc-400 dark:bg-zinc-800 dark:text-zinc-500">+</span>
                </button>

                <button
                    type="button"
                    @click="sidebarOpen = false"
                    class="rounded-lg p-2 text-zinc-400 hover:bg-zinc-200/60 hover:text-zinc-700 md:hidden dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                    title="Close sidebar" aria-label="Close sidebar"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            {{-- Search Filter --}}
            <div class="relative mb-2.5">
                <input
                    type="text"
                    x-model="searchQuery"
                    @input.debounce.300ms="fetchSearchResults()"
                    placeholder="Search chats..."
                    class="w-full rounded-xl border border-zinc-200/90 bg-white py-1.5 pe-3 ps-8 text-xs text-zinc-800 placeholder:text-zinc-400 focus:border-zinc-400 focus:outline-none focus:ring-1 focus:ring-zinc-400/20 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-200 dark:placeholder:text-zinc-500 dark:focus:border-zinc-700 dark:focus:ring-zinc-700/30"
                />
                <svg class="pointer-events-none absolute start-2.5 top-2.5 h-3.5 w-3.5 text-zinc-400 dark:text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <button
                    x-show="searchQuery"
                    @click="searchQuery = ''; searchResults = [];"
                    class="absolute end-2 top-2 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200"
                >
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            {{-- Sessions List Grouped by Timeline --}}
            <div wire:ignore class="flex-1 space-y-3.5 overflow-y-auto pe-1 text-xs">
                <template x-if="filteredSessions.length === 0">
                    <div class="p-4 text-center text-xs text-zinc-400 dark:text-zinc-500">
                        <span x-text="searchQuery ? 'No chats matching search.' : 'No conversations yet.'"></span>
                    </div>
                </template>

                {{-- Group: Today --}}
                <div x-show="groupedSessions.today.length > 0">
                    <p class="px-2 pb-1 text-[10px] font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Today</p>
                    <div class="space-y-0.5">
                        <template x-for="item in groupedSessions.today" :key="item.id">
                            <div
                                @click="selectSession(item)" @keydown.enter.prevent="selectSession(item)" @keydown.space.prevent="selectSession(item)" role="button" tabindex="0"
                                :class="activeSessionId === item.id || activeSessionUuid === item.uuid ? 'bg-zinc-200/70 text-zinc-900 font-medium dark:bg-zinc-800/80 dark:text-zinc-100' : 'text-zinc-600 hover:bg-zinc-200/40 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200'"
                                class="group relative flex cursor-pointer items-center justify-between rounded-lg px-2.5 py-1.5 transition"
                            >
                                <div class="flex min-w-0 items-center gap-2">
                                    <svg class="h-3.5 w-3.5 shrink-0 text-zinc-400 group-hover:text-zinc-600 dark:text-zinc-500 dark:group-hover:text-zinc-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                                    </svg>
                                    <span class="truncate" x-text="item.title"></span>
                                </div>
                                <div class="flex shrink-0 items-center gap-0.5 opacity-100 transition md:gap-1 md:opacity-0 md:group-hover:opacity-100 md:group-focus-within:opacity-100 md:focus-within:opacity-100">
                                    <button
                                        type="button"
                                        @click.stop="copyConversationLink(item)"
                                        class="rounded p-1.5 text-zinc-400 hover:text-zinc-700 md:p-0.5 dark:text-zinc-500 dark:hover:text-zinc-200"
                                        title="Copy link" aria-label="Copy link"
                                    >
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                                        </svg>
                                    </button>
                                    <button
                                        type="button"
                                        @click.stop="confirmDeleteSession(item.id || item.uuid)"
                                        class="rounded p-1.5 text-zinc-400 hover:text-red-500 md:p-0.5 dark:text-zinc-500 dark:hover:text-red-400"
                                        title="Delete chat" aria-label="Delete chat"
                                    >
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Group: Yesterday --}}
                <div x-show="groupedSessions.yesterday.length > 0">
                    <p class="px-2 pb-1 text-[10px] font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Yesterday</p>
                    <div class="space-y-0.5">
                        <template x-for="item in groupedSessions.yesterday" :key="item.id">
                            <div
                                @click="selectSession(item)" @keydown.enter.prevent="selectSession(item)" @keydown.space.prevent="selectSession(item)" role="button" tabindex="0"
                                :class="activeSessionId === item.id || activeSessionUuid === item.uuid ? 'bg-zinc-200/70 text-zinc-900 font-medium dark:bg-zinc-800/80 dark:text-zinc-100' : 'text-zinc-600 hover:bg-zinc-200/40 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200'"
                                class="group relative flex cursor-pointer items-center justify-between rounded-lg px-2.5 py-1.5 transition"
                            >
                                <div class="flex min-w-0 items-center gap-2">
                                    <svg class="h-3.5 w-3.5 shrink-0 text-zinc-400 group-hover:text-zinc-600 dark:text-zinc-500 dark:group-hover:text-zinc-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                                    </svg>
                                    <span class="truncate" x-text="item.title"></span>
                                </div>
                                <div class="flex shrink-0 items-center gap-0.5 opacity-100 transition md:gap-1 md:opacity-0 md:group-hover:opacity-100 md:group-focus-within:opacity-100 md:focus-within:opacity-100">
                                    <button
                                        type="button"
                                        @click.stop="copyConversationLink(item)"
                                        class="rounded p-1.5 text-zinc-400 hover:text-zinc-700 md:p-0.5 dark:text-zinc-500 dark:hover:text-zinc-200"
                                        title="Copy link" aria-label="Copy link"
                                    >
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                                        </svg>
                                    </button>
                                    <button
                                        type="button"
                                        @click.stop="confirmDeleteSession(item.id || item.uuid)"
                                        class="rounded p-1.5 text-zinc-400 hover:text-red-500 md:p-0.5 dark:text-zinc-500 dark:hover:text-red-400"
                                        title="Delete chat" aria-label="Delete chat"
                                    >
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Group: Previous 7 Days --}}
                <div x-show="groupedSessions.previousWeek.length > 0">
                    <p class="px-2 pb-1 text-[10px] font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Previous 7 Days</p>
                    <div class="space-y-0.5">
                        <template x-for="item in groupedSessions.previousWeek" :key="item.id">
                            <div
                                @click="selectSession(item)" @keydown.enter.prevent="selectSession(item)" @keydown.space.prevent="selectSession(item)" role="button" tabindex="0"
                                :class="activeSessionId === item.id || activeSessionUuid === item.uuid ? 'bg-zinc-200/70 text-zinc-900 font-medium dark:bg-zinc-800/80 dark:text-zinc-100' : 'text-zinc-600 hover:bg-zinc-200/40 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200'"
                                class="group relative flex cursor-pointer items-center justify-between rounded-lg px-2.5 py-1.5 transition"
                            >
                                <div class="flex min-w-0 items-center gap-2">
                                    <svg class="h-3.5 w-3.5 shrink-0 text-zinc-400 group-hover:text-zinc-600 dark:text-zinc-500 dark:group-hover:text-zinc-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                                    </svg>
                                    <span class="truncate" x-text="item.title"></span>
                                </div>
                                <div class="flex shrink-0 items-center gap-0.5 opacity-100 transition md:gap-1 md:opacity-0 md:group-hover:opacity-100 md:group-focus-within:opacity-100 md:focus-within:opacity-100">
                                    <button
                                        type="button"
                                        @click.stop="copyConversationLink(item)"
                                        class="rounded p-1.5 text-zinc-400 hover:text-zinc-700 md:p-0.5 dark:text-zinc-500 dark:hover:text-zinc-200"
                                        title="Copy link" aria-label="Copy link"
                                    >
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                                        </svg>
                                    </button>
                                    <button
                                        type="button"
                                        @click.stop="confirmDeleteSession(item.id || item.uuid)"
                                        class="rounded p-1.5 text-zinc-400 hover:text-red-500 md:p-0.5 dark:text-zinc-500 dark:hover:text-red-400"
                                        title="Delete chat" aria-label="Delete chat"
                                    >
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Group: Older --}}
                <div x-show="groupedSessions.older.length > 0">
                    <p class="px-2 pb-1 text-[10px] font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Older</p>
                    <div class="space-y-0.5">
                        <template x-for="item in groupedSessions.older" :key="item.id">
                            <div
                                @click="selectSession(item)" @keydown.enter.prevent="selectSession(item)" @keydown.space.prevent="selectSession(item)" role="button" tabindex="0"
                                :class="activeSessionId === item.id || activeSessionUuid === item.uuid ? 'bg-zinc-200/70 text-zinc-900 font-medium dark:bg-zinc-800/80 dark:text-zinc-100' : 'text-zinc-600 hover:bg-zinc-200/40 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200'"
                                class="group relative flex cursor-pointer items-center justify-between rounded-lg px-2.5 py-1.5 transition"
                            >
                                <div class="flex min-w-0 items-center gap-2">
                                    <svg class="h-3.5 w-3.5 shrink-0 text-zinc-400 group-hover:text-zinc-600 dark:text-zinc-500 dark:group-hover:text-zinc-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                                    </svg>
                                    <span class="truncate" x-text="item.title"></span>
                                </div>
                                <div class="flex shrink-0 items-center gap-0.5 opacity-100 transition md:gap-1 md:opacity-0 md:group-hover:opacity-100 md:group-focus-within:opacity-100 md:focus-within:opacity-100">
                                    <button
                                        type="button"
                                        @click.stop="copyConversationLink(item)"
                                        class="rounded p-1.5 text-zinc-400 hover:text-zinc-700 md:p-0.5 dark:text-zinc-500 dark:hover:text-zinc-200"
                                        title="Copy link" aria-label="Copy link"
                                    >
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                                        </svg>
                                    </button>
                                    <button
                                        type="button"
                                        @click.stop="confirmDeleteSession(item.id || item.uuid)"
                                        class="rounded p-1.5 text-zinc-400 hover:text-red-500 md:p-0.5 dark:text-zinc-500 dark:hover:text-red-400"
                                        title="Delete chat" aria-label="Delete chat"
                                    >
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            {{-- Link to ARC AI Activity --}}
            <div class="py-2.5">
                <a
                    href="{{ $activityUrl }}"
                    class="flex items-center justify-between rounded-xl border border-zinc-200/90 bg-white px-3 py-2 text-xs font-medium text-zinc-800 shadow-2xs transition hover:border-zinc-300 hover:bg-zinc-50 active:scale-[0.99] dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:border-zinc-700 dark:hover:bg-zinc-800"
                >
                    <span class="flex items-center gap-2">
                        <svg class="h-3.5 w-3.5 text-zinc-500 dark:text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                        <span>ARC AI Activity</span>
                    </span>
                    <svg class="h-3 w-3 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </a>
            </div>

            {{-- Sidebar Footer: Admin User Profile --}}
            <div class="mt-auto border-t border-zinc-200/80 pt-2.5 dark:border-zinc-800">
                <div class="flex items-center gap-2.5 rounded-xl px-2 py-1.5 text-zinc-700 dark:text-zinc-300">
                    <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-zinc-900 text-xs font-semibold text-white dark:bg-zinc-100 dark:text-zinc-900">
                        <span x-text="adminUser.name ? adminUser.name.charAt(0).toUpperCase() : 'A'"></span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-xs font-medium text-zinc-900 dark:text-zinc-100" x-text="adminUser.name || 'Admin User'"></p>
                        <p class="truncate text-[10px] text-zinc-400 dark:text-zinc-500" x-text="adminUser.email || 'admin@eduflow.test'"></p>
                    </div>
                </div>
            </div>
        </div>
    </aside>

    {{-- Main Workspace -------------------------------------------------------- --}}
    <div class="arc-workspace relative flex flex-1 flex-col overflow-hidden">
        {{-- Top App Bar --}}
        <header class="arc-header flex h-12 shrink-0 items-center justify-between border-b px-3 sm:px-4">
            {{-- Left side: Breadcrumb & Title --}}
            <div class="flex items-center gap-2">
                <a
                    href="{{ \Filament\Facades\Filament::getUrl() }}"
                    class="inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-xs font-medium text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-800 active:scale-95 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                >
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    <span>Dashboard</span>
                </a>

                <span class="h-3 w-px bg-zinc-200 dark:bg-zinc-800"></span>

                <button
                    type="button"
                    @click="sidebarOpen = !sidebarOpen"
                    class="rounded-lg p-1.5 text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 active:scale-95 dark:text-zinc-500 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                    :title="sidebarOpen ? 'Hide history' : 'Show history'" :aria-label="sidebarOpen ? 'Hide history' : 'Show history'"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 6h16M4 12h10M4 18h16" />
                    </svg>
                </button>

                <div class="flex items-center gap-1.5 rounded-lg px-1 py-1.5">
                    <span class="text-xs font-semibold text-zinc-900 dark:text-zinc-100">{{ $assistantName ?? 'ARC AI' }}</span>
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">Manual Chat</span>
                </div>
            </div>

            {{-- Right side: Model indicator & Quick actions --}}
            <div class="flex items-center gap-1.5">
                {{-- Quick Link to ARC AI Activity --}}
                <a
                    href="{{ $activityUrl }}"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200/90 bg-white px-2.5 py-1.5 text-xs font-medium text-zinc-700 shadow-2xs transition hover:border-zinc-300 hover:bg-zinc-50 active:scale-95 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800"
                >
                    <svg class="h-3.5 w-3.5 text-zinc-500 dark:text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                    </svg>
                    <span>ARC AI Activity</span>
                </a>

                <button
                    type="button"
                    role="switch"
                    :aria-checked="manualChatEnabled"
                    aria-label="Manual chat"
                    @click="toggleManualChat()"
                    :disabled="toggleSaving"
                    class="inline-flex items-center gap-2 rounded-lg border border-zinc-300 px-2.5 py-1.5 text-xs disabled:opacity-50 dark:border-zinc-700"
                    title="Controls manual input only. Does not start or stop financial cycles."
                >
                    <span>Manual chat</span>
                    <span class="font-semibold" x-text="manualChatEnabled ? 'On' : 'Off'"></span>
                </button>

                {{-- Provider/Model Badge --}}
                <div class="hidden items-center gap-1.5 rounded-full border border-zinc-200/80 bg-zinc-100/70 px-2.5 py-0.5 text-[11px] text-zinc-600 sm:flex dark:border-zinc-800/80 dark:bg-zinc-900/60 dark:text-zinc-400">
                    <span class="inline-block h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                    <span class="font-medium text-zinc-800 dark:text-zinc-200" x-text="providerLabel"></span>
                    <span class="text-zinc-300 dark:text-zinc-700">·</span>
                    <span class="font-mono text-[10px] text-zinc-500 dark:text-zinc-400" x-text="modelName"></span>
                </div>

                <div class="hidden text-[11px] text-zinc-400 lg:block dark:text-zinc-500">
                    <span x-text="workspace.name"></span>
                </div>

                <template x-if="activeSessionId || activeSessionUuid">
                    <button
                        type="button"
                        @click="copyConversationLink()"
                        class="flex items-center gap-1.5 rounded-lg border border-zinc-200/90 px-2 py-1 text-xs font-medium text-zinc-600 transition hover:bg-zinc-50 hover:text-zinc-900 active:scale-95 dark:border-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                        :title="linkCopied ? 'Link copied!' : 'Copy link'"
                    >
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                        </svg>
                        <span x-text="linkCopied ? 'Copied' : 'Share'" class="hidden text-[11px] sm:inline"></span>
                    </button>
                </template>

                <a
                    href="/admin/ai-chat"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="rounded-lg p-1.5 text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 active:scale-95 dark:text-zinc-500 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                    title="Open in new window" aria-label="Open in new window"
                >
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                    </svg>
                </a>

                <button
                    type="button"
                    @click="newChat()"
                    :disabled="!manualChatEnabled"
                    class="disabled:cursor-not-allowed disabled:opacity-50 rounded-lg p-1.5 text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 active:scale-95 dark:text-zinc-500 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                    title="Start fresh conversation" aria-label="Start fresh conversation"
                >
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                </button>
            </div>
        </header>

        <p x-show="toggleError" x-cloak role="alert" class="border-b border-red-200 p-3 text-xs text-red-700 dark:border-red-900 dark:text-red-300" x-text="toggleError"></p>

        {{-- Main Chat Content Area --}}
        <main wire:ignore class="relative flex flex-1 flex-col overflow-hidden">
            @if (! $providerCallsAllowed)
                <p class="mx-4 mt-4 rounded-xl border border-amber-300 p-3 text-xs text-amber-800 dark:border-amber-800 dark:text-amber-200">
                    External AI calls disabled. Enable advisory and accept the data disclosure in AI Settings before using manual chat. Policy decision history remains available.
                </p>
            @endif
            <p x-show="manualChatEnabled && !providerCallsAllowed" x-cloak role="status" class="mx-4 mt-4 rounded-xl border border-amber-300 p-3 text-xs text-amber-800 dark:border-amber-800 dark:text-amber-200">
                Manual chat is on. Sending is disabled until advisory is enabled and the data disclosure is accepted.
                <a href="{{ \App\Filament\Clusters\Settings\Pages\AiSettingsPage::getUrl(panel: 'admin') }}" class="font-medium underline underline-offset-4">Open AI Settings</a>
            </p>
            <div
                x-ref="messageContainer"
                @scroll="handleScroll()"
                class="flex-1 overflow-y-auto px-4 py-6 sm:px-8"
            >
                {{-- Welcome Screen (when no messages exist) --}}
                <template x-if="messages.length === 0">
                    <div class="relative flex min-h-full flex-col items-center justify-center px-4 py-8 text-center sm:py-12">
                        <div class="relative z-10 m-auto flex w-full max-w-xl flex-col items-center">
                            {{-- Brand mark --}}
                            <div class="welcome-logo mb-5 flex flex-col items-center">
                                <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-zinc-900 text-white shadow-xs ring-1 ring-black/5 dark:bg-zinc-100 dark:text-zinc-950 dark:ring-white/10 sm:h-12 sm:w-12">
                                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M12 2L14.2 9.8L22 12L14.2 14.2L12 22L9.8 14.2L2 12L9.8 9.8L12 2Z"/>
                                    </svg>
                                </div>
                            </div>

                            {{-- Clean, Simple Greeting --}}
                            <div class="welcome-greeting space-y-1 text-center">
                                <h1 class="text-xl sm:text-2xl font-semibold tracking-tight text-zinc-900 dark:text-zinc-100">
                                    <span x-text="greetingLine"></span>
                                </h1>
                                <p class="mx-auto max-w-md text-xs sm:text-sm text-zinc-500 dark:text-zinc-400 font-normal" x-text="greetingSubtext"></p>
                                <span class="hidden" x-text="timeGreeting"></span>
                            </div>

                            {{-- Minimalist Input Card --}}
                            <form
                                x-show="manualChatEnabled"
                                class="welcome-input mt-6 w-full max-w-xl"
                                @submit.prevent="sendMessage()"
                            >
                                <div
                                    class="arc-composer-card group relative rounded-2xl p-3 shadow-xs transition"
                                >
                                    <textarea
                                        x-ref="welcomeComposerInput"
                                        x-model="inputMessage"
                                        @keydown="handleKeyDown($event)"
                                        @input="autoGrowTextarea($event)"
                                        rows="2"
                                        placeholder="How can ARC help analyze your OpenSinop platform operations today?"
                                        class="min-h-[68px] w-full resize-none border-0 bg-transparent p-0 text-xs sm:text-sm text-zinc-900 placeholder:text-zinc-400 focus:outline-none focus:ring-0 leading-relaxed dark:text-zinc-100 dark:placeholder:text-zinc-500"
                                    ></textarea>

                                    {{-- Listening Feedback Banner --}}
                                    <div x-show="isListening" x-cloak class="mt-2 flex items-center justify-between gap-2 rounded-lg border border-red-500/20 bg-red-500/5 px-2.5 py-1.5 text-xs text-red-600 dark:text-red-400">
                                        <div class="flex items-center gap-2">
                                            <span class="relative flex h-2 w-2">
                                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-400 opacity-75"></span>
                                                <span class="relative inline-flex h-2 w-2 rounded-full bg-red-500"></span>
                                            </span>
                                            <span class="font-medium animate-pulse">Listening... speak into your microphone</span>
                                        </div>
                                        <button
                                            type="button"
                                            @click.stop.prevent="toggleVoice()"
                                            class="rounded bg-red-600 px-2 py-0.5 text-[11px] font-medium text-white shadow-xs hover:bg-red-700 dark:bg-red-500 dark:hover:bg-red-600"
                                        >
                                            Done
                                        </button>
                                    </div>

                                    <div x-show="voiceError" x-cloak class="mt-2 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50/90 p-2 text-xs text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200">
                                        <svg class="h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                        </svg>
                                        <div class="flex-1 leading-relaxed" x-text="voiceError"></div>
                                        <button type="button" @click="voiceError = null" class="text-amber-600 hover:text-amber-800 dark:text-amber-400 dark:hover:text-amber-200">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </div>

                                    <div class="mt-2 flex items-center justify-between border-t border-zinc-100 pt-2.5 dark:border-zinc-800/80">
                                        <div class="flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                                            <span class="inline-block h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                            <span class="font-medium" x-text="providerLabel"></span>
                                            <span class="text-zinc-300 dark:text-zinc-700">·</span>
                                            <span class="font-mono text-[11px] text-zinc-400 dark:text-zinc-500" x-text="modelName"></span>
                                        </div>

                                        <div class="flex items-center gap-1.5">
                                            {{-- Voice Dictation Button --}}
                                            <button
                                                type="button"
                                                @click.stop.prevent="toggleVoice()"
                                                :disabled="!chatAvailable || isStreaming"
                                                class="flex h-7 w-7 items-center justify-center rounded-lg border border-transparent text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 active:scale-95 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                                                :class="isListening ? 'bg-red-500 text-white hover:bg-red-600' : ''"
                                                title="Voice dictation" aria-label="Voice dictation"
                                            >
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z" />
                                                </svg>
                                            </button>

                                            {{-- Send Button --}}
                                            <button
                                                type="submit"
                                                :disabled="!chatAvailable || !inputMessage.trim() || isStreaming"
                                                class="flex h-7 w-7 items-center justify-center rounded-lg bg-zinc-900 text-white transition hover:bg-zinc-800 active:scale-95 disabled:opacity-20 disabled:pointer-events-none dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white"
                                                title="Send" aria-label="Send"
                                            >
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <p class="mt-2.5 text-center text-[11px] text-zinc-400 dark:text-zinc-500">
                                    ARC analyzes real-time OpenSinop financial and student data.
                                </p>
                            </form>

                            {{-- Clean Prompt Starters (2x2 grid) --}}
                            <p x-show="!manualChatEnabled" class="mt-6 text-xs text-zinc-500">Manual chat is off. Turn it on to show the chat input; saved conversations remain readable.</p>
                            <div x-show="chatAvailable" class="welcome-suggestions mt-6 w-full max-w-xl">
                                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                    <template x-for="starter in filteredPromptStarters" :key="starter.title">
                                        <button
                                            type="button"
                                            @click="fillAndSend(starter.prompt)"
                                            class="group flex flex-col justify-between rounded-xl border border-zinc-200/80 bg-zinc-50/50 p-3 text-left transition hover:border-zinc-300 hover:bg-zinc-100/70 active:scale-[0.99] dark:border-zinc-800/80 dark:bg-zinc-900/40 dark:hover:border-zinc-700 dark:hover:bg-zinc-800"
                                        >
                                            <div class="flex w-full items-center justify-between">
                                                <span class="text-xs font-medium text-zinc-800 group-hover:text-zinc-950 dark:text-zinc-200 dark:group-hover:text-white" x-text="starter.title"></span>
                                                <svg class="h-3 w-3 text-zinc-400 opacity-0 transition group-hover:translate-x-0.5 group-hover:opacity-100 dark:text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                                </svg>
                                            </div>
                                            <span class="mt-1 text-[11px] text-zinc-500 line-clamp-1 dark:text-zinc-400" x-text="starter.prompt"></span>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>

                {{-- Messages List --}}
                <div class="mx-auto max-w-3xl space-y-6">
                    <template x-for="(msg, index) in messages" :key="index">
                        <div class="flex flex-col gap-2">
                            {{-- User Message --}}
                            <template x-if="msg.role === 'user'">
                                <div class="flex items-start justify-end gap-2.5">
                                    <div class="arc-user-bubble max-w-[85%] rounded-2xl px-4 py-2.5 text-xs leading-relaxed sm:text-sm shadow-2xs">
                                        <div class="whitespace-pre-wrap font-sans" x-text="msg.content"></div>
                                    </div>
                                    <div class="arc-user-avatar flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-xs font-semibold shadow-2xs">
                                        <span x-text="adminUser.name ? adminUser.name.charAt(0).toUpperCase() : 'A'"></span>
                                    </div>
                                </div>
                            </template>

                            {{-- Assistant Message --}}
                            <template x-if="msg.role === 'assistant'">
                                <div class="flex items-start gap-2.5">
                                    <div class="arc-assistant-avatar flex h-7 w-7 shrink-0 items-center justify-center rounded-lg shadow-2xs">
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor">
                                            <path d="M12 2L14.2 9.8L22 12L14.2 14.2L12 22L9.8 14.2L2 12L9.8 9.8L12 2Z"/>
                                        </svg>
                                    </div>

                                    <div class="min-w-0 flex-1 space-y-2.5">
                                        {{-- Thinking / Loading Indicator with 3 animated bouncing dots --}}
                                        <div
                                            x-show="msg.typing && !msg.content && !msg.thinking"
                                            class="inline-flex items-center gap-2 rounded-xl border border-zinc-200/80 bg-zinc-50/80 px-3 py-1.5 text-xs text-zinc-600 shadow-2xs dark:border-zinc-800 dark:bg-zinc-900/60 dark:text-zinc-300"
                                        >
                                            <div class="flex h-3.5 w-3.5 items-center justify-center text-zinc-500 dark:text-zinc-400">
                                                <svg class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"></circle>
                                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                                </svg>
                                            </div>
                                            <span class="font-medium text-zinc-700 dark:text-zinc-200">Thinking</span>
                                            <span class="inline-flex items-center gap-1 text-zinc-500 dark:text-zinc-400">
                                                <span class="thinking-dot"></span>
                                                <span class="thinking-dot"></span>
                                                <span class="thinking-dot"></span>
                                            </span>
                                            <span
                                                x-show="msg.elapsedSeconds"
                                                class="font-mono text-[10px] text-zinc-400 dark:text-zinc-500"
                                                x-text="msg.elapsedSeconds + 's'"
                                            ></span>
                                        </div>

                                        {{-- Live Reasoning Process Accordion --}}
                                        <div x-show="msg.thinking" class="arc-card rounded-xl p-2.5 text-xs">
                                            <details :open="msg.thinkingOpen">
                                                <summary class="flex cursor-pointer items-center justify-between font-medium text-zinc-700 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-zinc-100">
                                                    <div class="flex items-center gap-1.5">
                                                        <svg
                                                            class="h-3.5 w-3.5 text-zinc-500"
                                                            :class="msg.typing && !msg.content ? 'animate-spin' : ''"
                                                            fill="none"
                                                            viewBox="0 0 24 24"
                                                            stroke="currentColor"
                                                        >
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                                        </svg>
                                                        <span x-text="msg.typing && !msg.content ? 'Thinking' : 'Reasoning process'"></span>
                                                        <span x-show="msg.typing && !msg.content" class="inline-flex items-center gap-1 text-zinc-500">
                                                            <span class="thinking-dot"></span>
                                                            <span class="thinking-dot"></span>
                                                            <span class="thinking-dot"></span>
                                                        </span>
                                                    </div>
                                                    <span
                                                        x-show="msg.thinkingMs || msg.elapsedSeconds"
                                                        class="font-mono text-[10px] text-zinc-400"
                                                        x-text="(msg.thinkingMs || msg.elapsedSeconds) + 's'"
                                                    ></span>
                                                </summary>
                                                <div class="mt-2 whitespace-pre-wrap border-t border-zinc-200/60 pt-2 font-mono text-[11px] leading-relaxed text-zinc-600 dark:border-zinc-800 dark:text-zinc-400" x-text="msg.thinking"></div>
                                            </details>
                                        </div>

                                        {{-- Message Body (Markdown formatted) with streaming cursor --}}
                                        <div x-show="msg.content" class="flex items-start">
                                            <div
                                                class="prose prose-sm dark:prose-invert max-w-none pt-0.5 text-xs leading-relaxed text-zinc-800 sm:text-sm dark:text-zinc-200"
                                                x-html="formatMarkdown(msg.content)"
                                            ></div>
                                            <span
                                                x-show="msg.typing"
                                                class="mt-1 ml-0.5 inline-block h-3.5 w-1.5 animate-pulse rounded-xs bg-zinc-900 dark:bg-zinc-100"
                                                title="Generating..." aria-label="Generating..."
                                            ></span>
                                        </div>

                                        {{-- Message Actions (Copy & Read Aloud) --}}
                                        <div x-show="!msg.typing && msg.content" class="flex items-center gap-2 pt-0.5">
                                            <button
                                                type="button"
                                                @click="copyText(msg.content); copiedMessageId = msg.content; setTimeout(() => { if (copiedMessageId === msg.content) copiedMessageId = null }, 1500)"
                                                class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-500 dark:hover:bg-zinc-800 dark:hover:text-zinc-300"
                                                title="Copy message" aria-label="Copy message"
                                            >
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                                </svg>
                                                <span x-text="copiedMessageId === msg.content ? 'Copied' : 'Copy'"></span>
                                            </button>

                                            <button
                                                type="button"
                                                @click="speakText(msg.content)"
                                                class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-500 dark:hover:bg-zinc-800 dark:hover:text-zinc-300"
                                                :title="speakingContent === msg.content ? 'Stop speaking' : 'Read aloud with voice'" :aria-label="speakingContent === msg.content ? 'Stop speaking' : 'Read aloud with voice'"
                                            >
                                                <svg x-show="speakingContent !== msg.content" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.536 8.464a5 5 0 010 7.072m2.828-9.9a9 9 0 010 12.728M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z" />
                                                </svg>
                                                <svg x-show="speakingContent === msg.content" class="h-3.5 w-3.5 text-zinc-800 dark:text-zinc-200 animate-pulse" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 10a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z" />
                                                </svg>
                                                <span x-text="speakingContent === msg.content ? 'Speaking...' : 'Listen'"></span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </div>

            <button
                x-show="showNewMessages"
                x-cloak
                type="button"
                @click="pinnedToBottom = true; showNewMessages = false; scrollToBottom(true)"
                class="absolute bottom-28 left-1/2 z-20 -translate-x-1/2 rounded-full border border-zinc-200/90 bg-white px-3 py-1.5 text-xs font-medium text-zinc-700 shadow-md transition hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800"
            >
                New messages
            </button>

            {{-- Docked Prompt Composer (when in conversation) -------------------------------------------- --}}
            <div
                x-show="messages.length > 0 && manualChatEnabled"
                x-cloak
                class="arc-footer-dock border-t p-3 sm:p-4 backdrop-blur-md"
            >
                <div class="mx-auto max-w-3xl">
                    <form @submit.prevent="sendMessage()" class="arc-composer-card relative flex flex-col rounded-2xl p-2.5 shadow-xs transition">
                        <textarea
                            x-ref="composerInput"
                            x-model="inputMessage"
                            @keydown="handleKeyDown($event)"
                            @input="autoGrowTextarea($event)"
                            rows="2"
                            placeholder="Ask ARC about treasury reserves, assistance requests, tuition accounts, or student records..."
                            class="w-full resize-none border-0 bg-transparent px-1 py-1 text-xs sm:text-sm text-zinc-900 placeholder:text-zinc-400 focus:outline-none focus:ring-0 leading-relaxed dark:text-zinc-100 dark:placeholder:text-zinc-500"
                        ></textarea>

                        {{-- Listening Feedback Banner --}}
                        <div x-show="isListening" x-cloak class="mb-2 flex items-center justify-between gap-2 rounded-lg border border-red-500/20 bg-red-500/5 px-2.5 py-1.5 text-xs text-red-600 dark:text-red-400">
                            <div class="flex items-center gap-2">
                                <span class="relative flex h-2 w-2">
                                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-400 opacity-75"></span>
                                    <span class="relative inline-flex h-2 w-2 rounded-full bg-red-500"></span>
                                </span>
                                <span class="font-medium animate-pulse">Listening... speak into your microphone</span>
                            </div>
                            <button
                                type="button"
                                @click.stop.prevent="toggleVoice()"
                                class="rounded bg-red-600 px-2 py-0.5 text-[11px] font-medium text-white shadow-xs hover:bg-red-700 dark:bg-red-500 dark:hover:bg-red-600"
                            >
                                Done
                            </button>
                        </div>

                        <div x-show="voiceError" x-cloak class="mb-2 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50/90 p-2 text-xs text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200">
                            <svg class="h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                            <div class="flex-1 leading-relaxed" x-text="voiceError"></div>
                            <button type="button" @click="voiceError = null" class="text-amber-600 hover:text-amber-800 dark:text-amber-400 dark:hover:text-amber-200">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <div class="flex items-center justify-between border-t border-zinc-100 pt-2 dark:border-zinc-800/80">
                            <div class="flex items-center gap-1.5 px-1 text-[11px] text-zinc-400 dark:text-zinc-500">
                                <span class="hidden sm:inline">Press</span>
                                <kbd class="rounded border border-zinc-200 bg-zinc-50 px-1.5 py-0.5 text-[10px] font-mono text-zinc-600 dark:border-zinc-800 dark:bg-zinc-800 dark:text-zinc-400">Enter</kbd>
                                <span class="hidden sm:inline">to send</span>
                                <span class="hidden text-zinc-300 sm:inline dark:text-zinc-700">·</span>
                                <kbd class="hidden rounded border border-zinc-200 bg-zinc-50 px-1.5 py-0.5 text-[10px] font-mono text-zinc-600 sm:inline-block dark:border-zinc-800 dark:bg-zinc-800 dark:text-zinc-400">Shift + Enter</kbd>
                                <span class="hidden sm:inline">for newline</span>
                            </div>

                            <div class="flex items-center gap-1.5">
                                {{-- Voice Dictation Button --}}
                                <button
                                    type="button"
                                    @click.stop.prevent="toggleVoice()"
                                    :disabled="!chatAvailable || isStreaming"
                                    class="flex h-7 w-7 items-center justify-center rounded-lg border border-transparent text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 active:scale-95 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                                    :class="isListening ? 'bg-red-500 text-white hover:bg-red-600' : ''"
                                    title="Voice dictation" aria-label="Voice dictation"
                                >
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z" />
                                    </svg>
                                </button>

                                <button
                                    x-show="isStreaming"
                                    x-cloak
                                    type="button"
                                    @click="stopStreaming()"
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-900 px-3 py-1 text-xs font-medium text-white transition hover:bg-zinc-800 active:scale-95 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white"
                                >
                                    <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 24 24">
                                        <rect x="6" y="6" width="12" height="12" rx="2" />
                                    </svg>
                                    <span>Stop</span>
                                </button>

                                <button
                                    x-show="!isStreaming"
                                    x-cloak
                                    type="submit"
                                    :disabled="!chatAvailable || !inputMessage.trim() || isStreaming"
                                    class="flex h-7 w-7 items-center justify-center rounded-lg bg-zinc-900 text-white transition hover:bg-zinc-800 active:scale-95 disabled:opacity-20 disabled:pointer-events-none dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white"
                                    title="Send" aria-label="Send"
                                >
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </form>

                    <p class="mt-2 text-center text-[10px] text-zinc-400 dark:text-zinc-500">
                        ARC connects to real-time OpenSinop financial and student data.
                    </p>
                </div>
            </div>
        </main>
    </div>

    @include('filament.pages.ai-chat-style')

    @include('filament.pages.ai-chat-script')
</div>
