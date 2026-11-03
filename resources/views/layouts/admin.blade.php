<?php

use Illuminate\Support\Facades\Auth;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Pivot Admin Dashboard')</title>

    <!-- Font Awesome Icons & Custom Design System CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="{{ asset('assets/css/admin.css') }}">

    <!-- Anti-flicker Theme Script -->
    <script>
        const savedTheme = localStorage.getItem('pivot_theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);
    </script>
</head>

<body>
    <!-- Mobile sidebar backdrop overlay -->
    <div class="sidebar-overlay" id="sidebar-overlay"></div>

    <div class="app-wrapper">

        <!-- Sidebar Navigation -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <img src="{{ asset('assets/img/logo.png') }}"
                    alt="Pivot Logo"
                    style="width: 36px; height: 36px; border-radius: 8px; object-fit: cover;">
                <span class="sidebar-brand-title">Pivot Admin</span>
            </div>

            <nav class="sidebar-nav">
                <!-- Core Section -->
                <div class="nav-section-title">Core</div>
                <a href="{{ route('admin.dashboard') }}" class="nav-item {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
                    <i class="fa-solid fa-chart-pie"></i>
                    <span>Dashboard</span>
                </a>

                <!-- User Management Section -->
                <div class="nav-section-title">User Management</div>
                <a href="{{ route('admin.users.index') }}" class="nav-item {{ request()->routeIs('admin.users.*') ? 'active' : '' }}">
                    <i class="fa-solid fa-users"></i>
                    <span>Users</span>
                </a>
                <a href="{{ route('admin.user-profiles.index') }}" class="nav-item {{ request()->routeIs('admin.user-profiles.*') ? 'active' : '' }}">
                    <i class="fa-solid fa-id-card"></i>
                    <span>User Profiles</span>
                </a>

                <!-- Content & Program Section -->
                <div class="nav-section-title">Content & Program</div>
                <a href="{{ route('admin.events.index') }}" class="nav-item {{ request()->routeIs('admin.events.*') ? 'active' : '' }}">
                    <i class="fa-solid fa-calendar-days"></i>
                    <span>Events</span>
                </a>
                <a href="{{ route('admin.activities.index') }}" class="nav-item {{ request()->routeIs('admin.activities.*') ? 'active' : '' }}">
                    <i class="fa-solid fa-person-running"></i>
                    <span>Activities</span>
                </a>
                <a href="{{ route('admin.categories.index') }}" class="nav-item {{ request()->routeIs('admin.categories.*') ? 'active' : '' }}">
                    <i class="fa-solid fa-layer-group"></i>
                    <span>Categories</span>
                </a>
                <a href="{{ route('admin.challenge-packs.index') }}" class="nav-item {{ request()->routeIs('admin.challenge-packs.*') ? 'active' : '' }}">
                    <i class="fa-solid fa-trophy"></i>
                    <span>Challenge Packs</span>
                </a>
                <a href="{{ route('admin.research-articles.index') }}" class="nav-item {{ request()->routeIs('admin.research-articles.*') ? 'active' : '' }}">
                    <i class="fa-solid fa-book-open"></i>
                    <span>Research Articles</span>
                </a>

                <a href="{{ route('admin.app-analyze.index') }}" class="nav-item {{ request()->routeIs('admin.app-analyze.*') ? 'active' : '' }}">
                    <i class="fa-solid fa-magnifying-glass-chart"></i>
                    <span>App Analytics</span>
                </a>

                <!-- Monetization Section -->
                <div class="nav-section-title">Monetization</div>
                <a href="{{ route('admin.subscriptions.index') }}" class="nav-item {{ request()->routeIs('admin.subscriptions.*') ? 'active' : '' }}">
                    <i class="fa-solid fa-credit-card"></i>
                    <span>Subscriptions</span>
                </a>
                <!-- <a href="#" class="nav-item {{ request()->routeIs('admin.payment-history.*') ? 'active' : '' }}">
                    <i class="fa-solid fa-receipt"></i>
                    <span>Payment History</span>
                </a> -->
                <a href="{{ route('admin.promo-codes.index') }}" class="nav-item {{ request()->routeIs('admin.promo-codes.*') ? 'active' : '' }}">
                    <i class="fa-solid fa-ticket"></i>
                    <span>Promo Codes</span>
                </a>

                <!-- System Section -->
                <div class="nav-section-title">System</div>
                <a href="{{ route('admin.profile.index') }}" class="nav-item {{ request()->routeIs('admin.profile.*') ? 'active' : '' }}">
                    <i class="fa-solid fa-user-gear"></i>
                    <span>Profile & Security</span>
                </a>
                <!-- <a href="{{ route('admin.profile.index') }}" class="nav-item {{ request()->routeIs('admin.settings.*') ? 'active' : '' }}">
                    <i class="fa-solid fa-gear"></i>
                    <span>Settings</span>
                </a> -->
            </nav>
        </aside>

        <!-- Main Content Area -->
        <div class="main-content">

            <!-- Top Navigation Bar -->
            <header class="topbar">
                <div class="topbar-left">
                    <button class="btn btn-secondary btn-icon" id="sidebar-toggle" title="Toggle Sidebar">
                        <i class="fa-solid fa-bars"></i>
                    </button>
                </div>

                <div class="topbar-right">
                    <!-- Theme Mode Switcher -->
                    <button class="btn btn-secondary btn-icon" id="theme-toggle" title="Toggle Theme">
                        <i class="fa-solid fa-moon" id="theme-icon"></i>
                    </button>

                    <!-- Authenticated User Info -->
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <div style="width: 38px; height: 38px; border-radius: 50%; background: var(--primary-light); color: var(--primary); display: flex; align-items: center; justify-content: center; font-weight: 700;">
                            {{ strtoupper(substr(Auth::user()->name ?? 'A', 0, 2)) }}
                        </div>
                        <div>
                            <div style="font-weight: 600; font-size: 0.875rem;">{{ Auth::user()->name ?? 'Admin User' }}</div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">{{ Auth::user()->email ?? 'admin@pivot.com' }}</div>
                        </div>
                    </div>

                    <!-- Logout Button -->
                    <form action="{{ route('admin.logout') }}" method="POST" style="margin-left: 0.5rem;">
                        @csrf
                        <button type="submit" class="btn btn-secondary btn-sm" title="Logout">
                            <i class="fa-solid fa-right-from-bracket"></i> Logout
                        </button>
                    </form>
                </div>
            </header>

            <!-- Main Dynamic Body -->
            <main class="content-body">
                @yield('content')
            </main>
        </div>
    </div>

    <!-- UI Interaction Scripts -->
    <script>
        // ── Sidebar Toggle Logic (mobile drawer vs desktop collapse) ──────────
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebar-toggle');
        const sidebarOverlay = document.getElementById('sidebar-overlay');

        function isMobile() {
            return window.innerWidth <= 768;
        }

        function openMobileSidebar() {
            sidebar.classList.add('open');
            sidebarOverlay.classList.add('active');
            document.body.style.overflow = 'hidden'; // prevent scroll behind drawer
        }

        function closeMobileSidebar() {
            sidebar.classList.remove('open');
            sidebarOverlay.classList.remove('active');
            document.body.style.overflow = '';
        }

        sidebarToggle.addEventListener('click', () => {
            if (isMobile()) {
                // Mobile: toggle drawer open/close
                if (sidebar.classList.contains('open')) {
                    closeMobileSidebar();
                } else {
                    openMobileSidebar();
                }
            } else {
                // Desktop: collapse/expand sidebar width
                sidebar.classList.toggle('collapsed');
            }
        });

        // Close sidebar when overlay backdrop is tapped
        sidebarOverlay.addEventListener('click', closeMobileSidebar);

        // Close sidebar on nav-item click (mobile UX)
        sidebar.querySelectorAll('.nav-item').forEach(item => {
            item.addEventListener('click', () => {
                if (isMobile()) closeMobileSidebar();
            });
        });

        // Reset mobile state on resize to desktop
        window.addEventListener('resize', () => {
            if (!isMobile()) {
                closeMobileSidebar();
            }
        });

        // ── Dark/Light Mode Switcher ──────────────────────────────────────────
        const themeToggleBtn = document.getElementById('theme-toggle');
        const themeIcon = document.getElementById('theme-icon');

        function syncThemeIcon(theme) {
            themeIcon.className = theme === 'dark' ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
        }

        syncThemeIcon(localStorage.getItem('pivot_theme') || 'light');

        themeToggleBtn.addEventListener('click', () => {
            const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
            const nextTheme = currentTheme === 'light' ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', nextTheme);
            localStorage.setItem('pivot_theme', nextTheme);
            syncThemeIcon(nextTheme);
        });

        // ── Modal Helpers ─────────────────────────────────────────────────────
        function openModal(id) {
            const modal = document.getElementById(id);
            if (modal) modal.classList.add('active');
        }

        function closeModal(id) {
            const modal = document.getElementById(id);
            if (modal) modal.classList.remove('active');
        }

        // Close active modal when clicking outside on backdrop
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('modal-backdrop')) {
                e.target.classList.remove('active');
            }
        });

        // ── Debounced Auto-Search for Inputs ──────────────────────────────────
        let searchTimer = null;

        function debouncedSubmit(inputElement, delay = 450) {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                inputElement.form.submit();
            }, delay);
        }

        // Restore focus & position cursor at end of input on page load
        document.addEventListener('DOMContentLoaded', () => {
            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput && searchInput.value) {
                searchInput.focus();
                const val = searchInput.value;
                searchInput.value = '';
                searchInput.value = val;
            }
        });
    </script>
    @stack('scripts')
</body>

</html>