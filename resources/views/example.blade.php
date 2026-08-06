<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบบริหารข้อมูลความเสี่ยงและความเปราะบางของชุมชน - อ.เมือง จ.มหาสารคาม</title>
    
    <!-- Google Fonts: Anuphan -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Anuphan', 'sans-serif'],
                    },
                    colors: {
                        primary: '#1e3a8a',
                        surface: 'rgba(255, 255, 255, 0.75)',
                        surfaceActive: 'rgba(255, 255, 255, 1)',
                    }
                }
            }
        }
    </script>

    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Leaflet CSS for Map -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
    
    <style>
        body {
            margin: 0;
            padding: 0;
            overflow: hidden; /* Prevent scrolling, full screen app */
        }
        
        #map {
            width: 100vw;
            height: 100vh;
            position: absolute;
            top: 0;
            left: 0;
            z-index: 10;
        }

        /* Customize scrollbar for sidebar */
        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: rgba(241, 245, 249, 0.5);
            border-radius: 4px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* Hide map attribution for a cleaner dashboard look */
        .leaflet-control-attribution {
            background: rgba(255,255,255,0.7) !important;
            font-family: 'Anuphan', sans-serif !important;
            font-size: 10px !important;
        }
        .leaflet-control-zoom {
            border: none !important;
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1) !important;
            margin-top: 80px !important; /* Push down to clear top bar */
        }

        /* Sidebar State Styles */
        #main-sidebar {
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        #main-sidebar:not(.expanded) .hide-collapsed {
            display: none !important;
        }
        
        /* Show collapsed vertical icons container ONLY when not expanded */
        #main-sidebar.expanded .collapsed-icons-view {
            display: none !important;
        }
        #main-sidebar:not(.expanded) .collapsed-icons-view {
            display: flex !important;
        }

        /* Optional overlay to make clicking easier when collapsed */
        #main-sidebar:not(.expanded)::after {
            content: '';
            position: absolute;
            inset: 0;
            z-index: 10;
            cursor: pointer;
        }
        
        #sidebar-content {
            transition: opacity 0.3s ease-in-out;
        }
        #main-sidebar:not(.expanded) #sidebar-content {
            opacity: 0;
            pointer-events: none;
            height: 0;
            padding: 0;
            overflow: hidden;
        }
    </style>
</head>
<body class="font-sans antialiased text-slate-800 bg-slate-50 selection:bg-blue-200">

    <div id="map"></div>

    <!-- Top Bar (Fixed at top, high z-index) -->
    <header class="fixed top-0 left-0 w-full h-[72px] bg-white/95 backdrop-blur-md shadow-sm border-b border-slate-200 z-[60] flex items-center justify-between px-6 lg:px-8 transition-all">
        <!-- Logo & Title -->
        <div class="flex items-center gap-4 shrink-0">
            <div class="flex items-center justify-center text-white bg-blue-600 shadow-inner w-11 h-11 rounded-xl shrink-0">
                <i class="text-xl fa-solid fa-shield-halved"></i>
            </div>
            <div class="flex flex-col">
                <h1 class="text-[17px] lg:text-lg font-bold leading-tight text-slate-800 whitespace-nowrap">ระบบบริหารข้อมูลความเสี่ยง</h1>
                <p class="text-[11px] lg:text-xs text-slate-500 font-medium whitespace-nowrap mt-0.5">ความเปราะบางของชุมชน อ.เมือง จ.มหาสารคาม</p>
            </div>
        </div>

        <!-- Right Side Nav (Map Style, Search, Login, Last Update) -->
        <div class="flex items-center gap-3 lg:gap-5 shrink-0">
            <!-- Map Style Toggle -->
            <div class="items-center hidden p-1 border md:flex bg-slate-100/80 rounded-xl border-slate-200 shrink-0">
                <button onclick="setMapStyle('color')" id="btn-color-map" class="px-4 py-1.5 text-xs font-semibold rounded-lg bg-white shadow-sm text-blue-600 transition-all whitespace-nowrap">แผนที่สี</button>
                <button onclick="setMapStyle('gray')" id="btn-gray-map" class="px-4 py-1.5 text-xs font-semibold rounded-lg text-slate-500 hover:text-slate-800 transition-all whitespace-nowrap">ขาวดำ</button>
            </div>

            <!-- Current Date (Replaced Search) -->
            <div class="items-center hidden px-4 py-2 transition-all border rounded-full lg:flex bg-slate-100/80 border-slate-200 shrink-0">
                <i class="text-sm fa-regular fa-calendar-check text-slate-400"></i>
                <span id="current-date-text" class="ml-2 text-xs font-semibold lg:text-sm text-slate-700">กำลังโหลดข้อมูล...</span>
            </div>

            <!-- ปุ่มเข้าสู่ระบบ -->
            <button onclick="openLoginModal()" class="flex items-center gap-2 px-6 py-2 text-xs font-semibold text-white transition-all bg-blue-600 rounded-full shadow-sm lg:text-sm hover:bg-blue-700 active:scale-95 shrink-0 whitespace-nowrap">
                <i class="fa-solid fa-user-lock"></i>
                เข้าสู่ระบบ
            </button>
        </div>
    </header>

    <!-- Floating Sidebar (Left Side) - Initially Collapsed -->
    <aside id="main-sidebar" class="fixed top-20 left-4 z-[50] w-16 bg-white/60 backdrop-blur-md border border-white/50 shadow-lg rounded-2xl overflow-hidden cursor-pointer hover:bg-white/95 hover:shadow-xl flex flex-col" onclick="activateSidebar()">
        
        <!-- Sidebar Header (Expanded Mode) -->
        <div class="hide-collapsed p-4 border-b border-slate-100/50 flex items-center justify-between transition-colors relative h-[65px]">
            <h2 id="sidebar-title" class="flex items-center font-semibold text-slate-800 whitespace-nowrap">
                <i class="mr-2 text-blue-500 fa-solid fa-layer-group"></i> ชั้นข้อมูล (Layers)
            </h2>
            <button id="minimize-btn" class="p-1 transition-opacity rounded-full text-slate-400 hover:text-slate-600" onclick="event.stopPropagation(); deactivateSidebar();">
                <i class="text-sm fa-solid fa-compress"></i>
            </button>
        </div>

        <!-- Collapsed Icons View (Only visible when collapsed) -->
        <div class="flex flex-col items-center w-full gap-4 py-4 transition-opacity duration-300 opacity-100 collapsed-icons-view">
            <div class="flex items-center justify-center w-8 h-8 rounded-full shadow-sm bg-slate-100 text-slate-500">
                <i class="text-sm fa-solid fa-layer-group"></i>
            </div>
            <div class="w-px h-4 bg-slate-200"></div> <!-- Divider -->
            
            <!-- Risk Icons Stack -->
            <div class="flex items-center justify-center w-8 h-8 text-blue-500 rounded-md bg-blue-50" title="อุทกภัย"><i class="text-sm fa-solid fa-water"></i></div>
            <div class="flex items-center justify-center w-8 h-8 text-red-500 rounded-md bg-red-50" title="อัคคีภัย"><i class="text-sm fa-solid fa-fire"></i></div>
            <div class="flex items-center justify-center w-8 h-8 text-teal-500 rounded-md bg-teal-50" title="วาตภัย"><i class="text-sm fa-solid fa-wind"></i></div>
            <div class="flex items-center justify-center w-8 h-8 text-orange-500 rounded-md bg-orange-50" title="คลื่นความร้อน"><i class="text-sm fa-solid fa-temperature-arrow-up"></i></div>
            <div class="flex items-center justify-center w-8 h-8 text-purple-500 rounded-md bg-purple-50" title="ฝุ่น PM 2.5"><i class="text-sm fa-solid fa-smog"></i></div>
        </div>

        <!-- Sidebar Content (Scrollable, visible when expanded) -->
        <div id="sidebar-content" class="flex-1 p-4 overflow-y-auto custom-scrollbar">
            
            <!-- SECTION 1: Risks -->
            <div class="mb-6">
                <h3 class="mb-3 text-xs font-bold tracking-wider uppercase text-slate-400">ประเภทความเสี่ยง (Risks)</h3>
                <div class="grid grid-cols-1 gap-2">
                    
                    <!-- แสดงทั้งหมด -->
                    <label class="flex items-center p-2.5 rounded-lg border border-slate-100 bg-slate-50/50 hover:bg-slate-100 cursor-pointer transition-colors group/item relative z-20">
                        <div class="flex items-center justify-center w-8 h-8 mr-3 transition-transform rounded-md shadow-sm bg-slate-200 text-slate-600 group-hover/item:scale-110">
                            <i class="fa-solid fa-layer-group"></i>
                        </div>
                        <div class="flex-1">
                            <span class="text-sm font-medium text-slate-700">แสดงทั้งหมด</span>
                        </div>
                        <input type="radio" name="riskFilter" value="all" checked class="w-4 h-4 border-gray-300 text-slate-600 focus:ring-slate-500" onchange="filterRisk('all')">
                    </label>

                    <!-- Flood -->
                    <label class="flex items-center p-2.5 rounded-lg border border-slate-100 bg-slate-50/50 hover:bg-blue-50 cursor-pointer transition-colors group/item relative z-20">
                        <div class="flex items-center justify-center w-8 h-8 mr-3 text-blue-600 transition-transform bg-blue-100 rounded-md shadow-sm group-hover/item:scale-110">
                            <i class="fa-solid fa-water"></i>
                        </div>
                        <div class="flex-1">
                            <span class="text-sm font-medium text-slate-700">อุทกภัย</span>
                        </div>
                        <input type="radio" name="riskFilter" value="flood" class="w-4 h-4 text-blue-600 border-gray-300 focus:ring-blue-500 accent-blue-600" onchange="filterRisk('flood')">
                    </label>

                    <!-- Fire -->
                    <label class="flex items-center p-2.5 rounded-lg border border-slate-100 bg-slate-50/50 hover:bg-red-50 cursor-pointer transition-colors group/item relative z-20">
                        <div class="flex items-center justify-center w-8 h-8 mr-3 text-red-600 transition-transform bg-red-100 rounded-md shadow-sm group-hover/item:scale-110">
                            <i class="fa-solid fa-fire"></i>
                        </div>
                        <div class="flex-1">
                            <span class="text-sm font-medium text-slate-700">อัคคีภัย</span>
                        </div>
                        <input type="radio" name="riskFilter" value="fire" class="w-4 h-4 text-red-600 border-gray-300 focus:ring-red-500 accent-red-500" onchange="filterRisk('fire')">
                    </label>

                    <!-- Windstorm -->
                    <label class="flex items-center p-2.5 rounded-lg border border-slate-100 bg-slate-50/50 hover:bg-teal-50 cursor-pointer transition-colors group/item relative z-20">
                        <div class="flex items-center justify-center w-8 h-8 mr-3 text-teal-600 transition-transform bg-teal-100 rounded-md shadow-sm group-hover/item:scale-110">
                            <i class="fa-solid fa-wind"></i>
                        </div>
                        <div class="flex-1">
                            <span class="text-sm font-medium text-slate-700">วาตภัย</span>
                        </div>
                        <input type="radio" name="riskFilter" value="wind" class="w-4 h-4 text-teal-600 border-gray-300 focus:ring-teal-500 accent-teal-600" onchange="filterRisk('wind')">
                    </label>

                    <!-- Heatwave -->
                    <label class="flex items-center p-2.5 rounded-lg border border-slate-100 bg-slate-50/50 hover:bg-orange-50 cursor-pointer transition-colors group/item relative z-20">
                        <div class="flex items-center justify-center w-8 h-8 mr-3 text-orange-600 transition-transform bg-orange-100 rounded-md shadow-sm group-hover/item:scale-110">
                            <i class="fa-solid fa-temperature-arrow-up"></i>
                        </div>
                        <div class="flex-1">
                            <span class="text-sm font-medium text-slate-700">คลื่นความร้อน</span>
                        </div>
                        <input type="radio" name="riskFilter" value="heat" class="w-4 h-4 text-orange-600 border-gray-300 focus:ring-orange-500 accent-orange-500" onchange="filterRisk('heat')">
                    </label>

                    <!-- PM 2.5 -->
                    <label class="flex items-center p-2.5 rounded-lg border border-slate-100 bg-slate-50/50 hover:bg-purple-50 cursor-pointer transition-colors group/item relative z-20">
                        <div class="flex items-center justify-center w-8 h-8 mr-3 text-purple-600 transition-transform bg-purple-100 rounded-md shadow-sm group-hover/item:scale-110">
                            <i class="fa-solid fa-smog"></i>
                        </div>
                        <div class="flex-1">
                            <span class="text-sm font-medium text-slate-700">PM 2.5</span>
                            <span class="block text-[10px] text-red-500 font-semibold">เฝ้าระวัง</span>
                        </div>
                        <input type="radio" name="riskFilter" value="pm25" class="w-4 h-4 text-purple-600 border-gray-300 focus:ring-purple-500 accent-purple-600" onchange="filterRisk('pm25')">
                    </label>

                </div>
            </div>

            <!-- SECTION 2: Communities List -->
            <div>
                <h3 class="mb-3 text-xs font-bold tracking-wider uppercase text-slate-400">พื้นที่ 12 ชุมชน (อ.เมือง)</h3>
                <ul class="relative z-20 space-y-1" id="community-list">
                    <!-- Javascript will populate this list -->
                </ul>
            </div>
        </div>
    </aside>

    <!-- Quick Stats Overlay (5 Cards) - Plain style, no side border -->
    <div id="stats-container" class="fixed bottom-6 right-6 left-[5rem] z-[40] hidden lg:flex gap-2 xl:gap-3 flex-nowrap justify-end pointer-events-none transition-all duration-400 ease-in-out">
        <!-- Card 1: อุทกภัย -->
        <div class="bg-white/90 backdrop-blur-md shadow-lg border border-slate-100 rounded-xl p-3 xl:p-4 flex-1 min-w-[120px] max-w-[160px] pointer-events-auto hover:-translate-y-1 transition-transform cursor-default shrink">
            <div class="flex items-start justify-between mb-2">
                <p class="text-[11px] xl:text-[12px] text-slate-500 font-medium leading-tight">พื้นที่เสี่ยง<br>อุทกภัย</p>
                <div class="flex items-center justify-center w-6 h-6 text-blue-600 rounded bg-blue-50 shrink-0"><i class="text-xs fa-solid fa-water"></i></div>
            </div>
            <div class="flex items-baseline gap-1.5">
                <span class="text-xl font-bold xl:text-2xl text-slate-800">2</span>
                <span class="text-[10px] xl:text-xs text-slate-500">ชุมชน</span>
            </div>
        </div>
        
        <!-- Card 2: อัคคีภัย -->
        <div class="bg-white/90 backdrop-blur-md shadow-lg border border-slate-100 rounded-xl p-3 xl:p-4 flex-1 min-w-[120px] max-w-[160px] pointer-events-auto hover:-translate-y-1 transition-transform cursor-default shrink">
            <div class="flex items-start justify-between mb-2">
                <p class="text-[11px] xl:text-[12px] text-slate-500 font-medium leading-tight">พื้นที่เสี่ยง<br>อัคคีภัย</p>
                <div class="flex items-center justify-center w-6 h-6 text-red-600 rounded bg-red-50 shrink-0"><i class="text-xs fa-solid fa-fire"></i></div>
            </div>
            <div class="flex items-baseline gap-1.5">
                <span class="text-xl font-bold xl:text-2xl text-slate-800">2</span>
                <span class="text-[10px] xl:text-xs text-slate-500">ชุมชน</span>
            </div>
        </div>

        <!-- Card 3: วาตภัย -->
        <div class="bg-white/90 backdrop-blur-md shadow-lg border border-slate-100 rounded-xl p-3 xl:p-4 flex-1 min-w-[120px] max-w-[160px] pointer-events-auto hover:-translate-y-1 transition-transform cursor-default shrink">
             <div class="flex items-start justify-between mb-2">
                <p class="text-[11px] xl:text-[12px] text-slate-500 font-medium leading-tight">พื้นที่เสี่ยง<br>วาตภัย</p>
                <div class="flex items-center justify-center w-6 h-6 text-teal-600 rounded bg-teal-50 shrink-0"><i class="text-xs fa-solid fa-wind"></i></div>
            </div>
            <div class="flex items-baseline gap-1.5">
                <span class="text-xl font-bold xl:text-2xl text-slate-800">2</span>
                <span class="text-[10px] xl:text-xs text-slate-500">ชุมชน</span>
            </div>
        </div>

        <!-- Card 4: คลื่นความร้อน -->
        <div class="bg-white/90 backdrop-blur-md shadow-lg border border-slate-100 rounded-xl p-3 xl:p-4 flex-1 min-w-[120px] max-w-[160px] pointer-events-auto hover:-translate-y-1 transition-transform cursor-default shrink">
            <div class="flex items-start justify-between mb-2">
                <p class="text-[11px] xl:text-[12px] text-slate-500 font-medium leading-tight">คลื่นความร้อน<br>เฉลี่ย</p>
                <div class="flex items-center justify-center w-6 h-6 text-orange-600 rounded bg-orange-50 shrink-0"><i class="text-xs fa-solid fa-temperature-arrow-up"></i></div>
            </div>
            <div class="flex items-baseline gap-1.5">
                <span class="text-xl font-bold xl:text-2xl text-slate-800">38</span>
                <span class="text-[10px] xl:text-xs text-slate-500">°C</span>
            </div>
        </div>

        <!-- Card 5: PM 2.5 -->
        <div class="bg-white/90 backdrop-blur-md shadow-lg border border-slate-100 rounded-xl p-3 xl:p-4 flex-1 min-w-[120px] max-w-[160px] pointer-events-auto hover:-translate-y-1 transition-transform cursor-default shrink">
            <div class="flex items-start justify-between mb-2">
                <p class="text-[11px] xl:text-[12px] text-slate-500 font-medium leading-tight">ค่าฝุ่น PM 2.5<br>เฉลี่ย</p>
                <div class="flex items-center justify-center w-6 h-6 text-purple-600 rounded bg-purple-50 shrink-0"><i class="text-xs fa-solid fa-smog"></i></div>
            </div>
            <div class="flex items-baseline gap-1.5">
                <span class="text-xl font-bold xl:text-2xl text-slate-800">68</span>
                <span class="text-[10px] xl:text-[11px] text-slate-500 font-medium text-red-500 ml-1">(เฝ้าระวัง)</span>
            </div>
        </div>
    </div>

    <!-- Custom Modern Login Modal -->
    <div id="login-modal" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-[100] flex items-center justify-center opacity-0 pointer-events-none transition-all duration-300" onclick="closeLoginModalOutside(event)">
        <!-- Modal Content Box -->
        <div id="modal-box" class="w-full max-w-md overflow-hidden transition-all duration-300 transform scale-95 bg-white border shadow-2xl opacity-0 rounded-2xl border-slate-100" onclick="event.stopPropagation()">
            <!-- Modal Header -->
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 bg-slate-50/50">
                <div class="flex items-center gap-2">
                    <span class="flex items-center justify-center w-8 h-8 text-blue-600 bg-blue-100 rounded-lg">
                        <i class="text-sm fa-solid fa-unlock-keyhole"></i>
                    </span>
                    <h3 class="text-base font-semibold text-slate-800">เข้าสู่ระบบการทำงาน</h3>
                </div>
                <button onclick="closeLoginModal()" class="flex items-center justify-center w-8 h-8 transition-all rounded-full text-slate-400 hover:text-slate-600 hover:bg-slate-100">
                    <i class="text-sm fa-solid fa-xmark"></i>
                </button>
            </div>
            <!-- Modal Body (Form) -->
            <form id="login-form" onsubmit="handleLoginSubmit(event)" class="p-6 space-y-4">
                <!-- Email Field -->
                <div>
                    <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">อีเมล (Email)</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                            <i class="fa-regular fa-envelope"></i>
                        </span>
                        <input type="email" required placeholder="example@domain.com" class="w-full pl-9 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm text-slate-800 placeholder-slate-400 focus:outline-none focus:bg-white focus:ring-2 focus:ring-blue-100 focus:border-blue-500 transition-all">
                    </div>
                </div>

                <!-- Password Field -->
                <div>
                    <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">รหัสผ่าน (Password)</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                            <i class="fa-solid fa-lock"></i>
                        </span>
                        <input type="password" required placeholder="••••••••" class="w-full pl-9 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm text-slate-800 placeholder-slate-400 focus:outline-none focus:bg-white focus:ring-2 focus:ring-blue-100 focus:border-blue-500 transition-all">
                    </div>
                </div>

                <div class="relative my-5">
                    <div class="absolute inset-0 flex items-center">
                        <div class="w-full border-t border-slate-200"></div>
                    </div>
                    <div class="relative flex justify-center text-sm">
                        <span class="px-3 text-xs font-medium bg-white text-slate-400">หรือ</span>
                    </div>
                </div>

                <!-- Google sign in helper visual indicator -->
                <div>
                    <button type="button" class="w-full py-2.5 border border-slate-200 hover:border-slate-300 rounded-xl text-sm text-slate-600 font-medium flex items-center justify-center gap-3 hover:bg-slate-50 transition-colors shadow-sm">
                        <svg class="w-4 h-4" viewBox="0 0 24 24">
                            <path fill="#EA4335" d="M12 5.04c1.62 0 3.08.56 4.22 1.65l3.15-3.15C17.45 1.74 14.93 1 12 1 7.37 1 3.4 3.66 1.48 7.55l3.82 2.96c.92-2.76 3.5-4.47 6.7-4.47z"/>
                            <path fill="#4285F4" d="M23.49 12.27c0-.81-.07-1.59-.2-2.34H12v4.44h6.44c-.28 1.47-1.11 2.71-2.35 3.54l3.66 2.84c2.14-1.97 3.38-4.87 3.38-8.48z"/>
                            <path fill="#FBBC05" d="M5.3 14.91c-.24-.72-.38-1.49-.38-2.28 0-.79.14-1.56.38-2.28L1.48 7.39C.53 9.3 0 11.45 0 13.73c0 2.28.53 4.43 1.48 6.34l3.82-3.16z"/>
                            <path fill="#34A853" d="M12 23c3.24 0 5.97-1.07 7.96-2.91l-3.66-2.84c-1.01.68-2.31 1.08-3.9 1.08-3.2 0-5.78-1.71-6.75-4.47L1.48 16.82C3.4 20.71 7.37 23 12 23z"/>
                        </svg>
                        เข้าระบบด้วยบัญชี @gmail
                    </button>
                </div>

                <!-- Footer Buttons inside Form -->
                <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                    <button type="button" onclick="closeLoginModal()" class="px-4 py-2 text-sm transition-all text-slate-500 hover:text-slate-800 hover:bg-slate-50 rounded-xl">ยกเลิก</button>
                    <button type="submit" class="px-5 py-2 text-sm font-medium text-white transition-all bg-blue-600 shadow-sm hover:bg-blue-700 rounded-xl shadow-blue-100 hover:shadow-md">เข้าสู่ระบบ</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Toast message for success login -->
    <div id="success-toast" class="fixed top-24 left-1/2 -translate-x-1/2 z-[100] transform translate-y-[-20px] opacity-0 pointer-events-none transition-all duration-300 max-w-sm w-full bg-slate-900 text-white py-3 px-5 rounded-2xl shadow-2xl flex items-center gap-3">
        <span class="flex items-center justify-center w-8 h-8 rounded-full bg-emerald-500/10 text-emerald-400 shrink-0">
            <i class="text-base fa-solid fa-circle-check"></i>
        </span>
        <div>
            <p class="text-sm font-semibold">เข้าสู่ระบบสำเร็จ</p>
            <p class="text-xs text-slate-400">ยินดีต้อนรับผู้บริหารระดับสูงเข้าสู่ระบบ</p>
        </div>
    </div>

    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    
    <script>
        // Set Last Update Text to Current Date Thai Format
        function updateDateText() {
            const date = new Date();
            const thaiMonths = ["มกราคม", "กุมภาพันธ์", "มีนาคม", "เมษายน", "พฤษภาคม", "มิถุนายน", "กรกฎาคม", "สิงหาคม", "กันยายน", "ตุลาคม", "พฤศจิกายน", "ธันวาคม"];
            
            const day = date.getDate();
            const month = thaiMonths[date.getMonth()];
            const year = date.getFullYear() + 543; // Convert to Buddhist Era

            const formattedDate = `${day} ${month} ${year}`;
            
            const dateEl = document.getElementById('current-date-text');
            if(dateEl) dateEl.innerText = formattedDate;
        }

        // Data Configuration
        const MAP_CENTER = [16.1852, 103.3015]; // อำเภอเมือง มหาสารคาม
        const MAP_ZOOM = 14;

        // Colors for risk types
        const riskColors = {
            flood: '#2563eb', // blue-600
            fire: '#dc2626',  // red-600
            wind: '#0d9488',  // teal-600
            heat: '#ea580c',  // orange-600
            pm25: '#9333ea',  // purple-600
            none: '#94a3b8'   // slate-400
        };

        // Mocking 12 Communities in Mueang Maha Sarakham with center points
        const communities = [
            { id: 1, name: "ชุมชนศรีสวัสดิ์", lat: 16.1882, lng: 103.3001, activeRisk: "flood" },
            { id: 2, name: "ชุมชนธัญญา", lat: 16.1795, lng: 103.3050, activeRisk: "none" },
            { id: 3, name: "ชุมชนตักสิลา", lat: 16.1910, lng: 103.2920, activeRisk: "fire" },
            { id: 4, name: "ชุมชนมหาชัย", lat: 16.1750, lng: 103.2950, activeRisk: "pm25" },
            { id: 5, name: "ชุมชนอภิสิทธิ์", lat: 16.1820, lng: 103.3150, activeRisk: "none" },
            { id: 6, name: "ชุมชนสามัคคี", lat: 16.2001, lng: 103.2990, activeRisk: "wind" },
            { id: 7, name: "ชุมชนบูรพา", lat: 16.1850, lng: 103.3200, activeRisk: "flood" },
            { id: 8, name: "ชุมชนโพธิ์ศรี", lat: 16.1700, lng: 103.3100, activeRisk: "none" },
            { id: 9, name: "ชุมชนพัฒนเจริญ", lat: 16.1950, lng: 103.3120, activeRisk: "pm25" },
            { id: 10, name: "ชุมชนหนองจิก", lat: 16.1650, lng: 103.2850, activeRisk: "heat" },
            { id: 11, name: "ชุมชนบ้านค้อ", lat: 16.2050, lng: 103.3050, activeRisk: "wind" },
            { id: 12, name: "ชุมชนอุปราช", lat: 16.1800, lng: 103.2800, activeRisk: "fire" }
        ];

        let map;
        let markersGroup = {};
        let colorTileLayer;
        let grayTileLayer;
        
        function initMap() {
            map = L.map('map', {
                zoomControl: false // Disable default to move it later
            }).setView(MAP_CENTER, MAP_ZOOM);

            // Define map tiles
            colorTileLayer = L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
                attribution: '&copy; OpenStreetMap',
                subdomains: 'abcd',
                maxZoom: 20
            });

            grayTileLayer = L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
                attribution: '&copy; OpenStreetMap',
                subdomains: 'abcd',
                maxZoom: 20
            });

            // Add default tile (color)
            colorTileLayer.addTo(map);

            // Add Zoom control at bottom right
            L.control.zoom({
                position: 'topright'
            }).addTo(map);

            // Create Layer Groups for toggling
            markersGroup = {
                flood: L.featureGroup().addTo(map),
                fire: L.featureGroup().addTo(map),
                wind: L.featureGroup().addTo(map),
                heat: L.featureGroup().addTo(map), 
                pm25: L.featureGroup().addTo(map),
                none: L.featureGroup().addTo(map) // For safe communities
            };

            // Add Community Markers to Map
            communities.forEach(com => {
                
                let fillColor = riskColors[com.activeRisk];
                let isRisky = com.activeRisk !== "none";
                
                // 1. ตัดคำว่า "ชุมชน" ออก เพื่อให้ชื่อสั้นลงและพอดีกับวงกลม
                let shortName = com.name.replace('ชุมชน', ''); 
                
                // 2. สร้าง Custom HTML Icon เป็นวงกลมตามสีความเสี่ยงพร้อมชื่อตรงกลาง (สีตัวหนังสือตามสีขอบ)
                const customIcon = L.divIcon({
                    className: '', // ลบค่าเริ่มต้นที่ทำให้มีกรอบสีขาว
                    html: `
                        <div style="background-color: ${fillColor}40; color: ${fillColor}; border-radius: 50%; width: 56px; height: 56px; display: flex; align-items: center; justify-content: center; font-size: 11.5px; font-family: 'Anuphan', sans-serif; font-weight: 500; text-align: center; border: 2px solid ${fillColor}; box-shadow: 0 4px 12px rgba(0,0,0,0.08); line-height: 1.1; padding: 2px; transition: all 0.3s ease; backdrop-filter: blur(2px);" class="cursor-pointer hover:scale-110">
                            ${shortName}
                        </div>
                    `,
                    iconSize: [56, 56],
                    iconAnchor: [28, 28], // ให้วงกลมอยู่ตรงจุดพิกัดพอดี
                    popupAnchor: [0, -28]
                });
                
                // สร้างจุด Marker บนแผนที่
                const marker = L.marker([com.lat, com.lng], { icon: customIcon });
                
                // Tooltip Content (Popup เมื่อกด)
                let riskNameTH = "ปลอดภัย / ความเสี่ยงต่ำ";
                if(com.activeRisk === "flood") riskNameTH = "อุทกภัย";
                if(com.activeRisk === "fire") riskNameTH = "อัคคีภัย";
                if(com.activeRisk === "wind") riskNameTH = "วาตภัย";
                if(com.activeRisk === "heat") riskNameTH = "คลื่นความร้อนสูง";
                if(com.activeRisk === "pm25") riskNameTH = "ฝุ่น PM 2.5 เกินมาตรฐาน";

                let badgeHtml = isRisky 
                    ? `<span class="inline-block px-2 py-1 text-[10px] font-semibold rounded bg-slate-100 border mt-2" style="color: ${fillColor}; border-color: ${fillColor}">ความเสี่ยงปัจจุบัน: ${riskNameTH}</span>`
                    : `<span class="inline-block px-2 py-1 text-[10px] font-semibold rounded bg-slate-100 border border-slate-300 text-slate-500 mt-2">สถานะปกติ</span>`;

                marker.bindPopup(`
                    <div class="font-sans min-w-[150px] text-center pb-1">
                        <h4 class="font-bold text-slate-800 text-sm mb-0.5">${com.name}</h4>
                        <p class="text-xs text-slate-500">อ.เมือง จ.มหาสารคาม</p>
                        ${badgeHtml}
                    </div>
                `);

                // Add to appropriate risk group
                if(markersGroup[com.activeRisk]) {
                    markersGroup[com.activeRisk].addLayer(marker);
                }
            });
        }

        // Toggle map style (Color / Grayscale)
        window.setMapStyle = function(style) {
            const btnColor = document.getElementById('btn-color-map');
            const btnGray = document.getElementById('btn-gray-map');

            if (style === 'color') {
                if (map.hasLayer(grayTileLayer)) map.removeLayer(grayTileLayer);
                colorTileLayer.addTo(map);
                
                btnColor.classList.add('bg-white', 'shadow-sm', 'text-blue-600');
                btnColor.classList.remove('text-slate-500', 'hover:text-slate-800');
                btnGray.classList.remove('bg-white', 'shadow-sm', 'text-blue-600');
                btnGray.classList.add('text-slate-500', 'hover:text-slate-800');
            } else {
                if (map.hasLayer(colorTileLayer)) map.removeLayer(colorTileLayer);
                grayTileLayer.addTo(map);
                
                btnGray.classList.add('bg-white', 'shadow-sm', 'text-blue-600');
                btnGray.classList.remove('text-slate-500', 'hover:text-slate-800');
                btnColor.classList.remove('bg-white', 'shadow-sm', 'text-blue-600');
                btnColor.classList.add('text-slate-500', 'hover:text-slate-800');
            }
        };

        // Render Community List in Sidebar
        function renderCommunities() {
            const listContainer = document.getElementById('community-list');
            listContainer.innerHTML = '';

            communities.forEach((com, index) => {
                const li = document.createElement('li');
                li.className = "flex items-center justify-between p-2 rounded hover:bg-slate-50 cursor-pointer transition-colors group/list text-sm border border-transparent hover:border-slate-100";
                
                // Find center of polygon to fly to
                li.onclick = () => focusMap(com.lat, com.lng);
                
                let dotColor = riskColors[com.activeRisk];

                li.innerHTML = `
                    <div class="flex items-center">
                        <span class="w-4 text-xs text-slate-400">${index + 1}.</span>
                        <span class="ml-2 transition-colors text-slate-700 group-hover/list:text-blue-600">${com.name}</span>
                    </div>
                    <div class="w-2.5 h-2.5 rounded-full shadow-sm" style="background-color: ${dotColor};"></div>
                `;
                listContainer.appendChild(li);
            });
        }

        // Filter Risks from Radio Buttons (เลือกความเสี่ยงได้ทีละอัน)
        window.filterRisk = function(selectedRisk) {
            // เอา Layer ทั้งหมดออกก่อน
            Object.keys(markersGroup).forEach(key => {
                if (map.hasLayer(markersGroup[key])) {
                    map.removeLayer(markersGroup[key]);
                }
            });

            // ใส่ Layer กลับเข้าไปตามเงื่อนไขที่เลือก
            if (selectedRisk === 'all') {
                // ถ้าเลือก 'แสดงทั้งหมด' ให้เพิ่มทุก Layer
                Object.keys(markersGroup).forEach(key => {
                    map.addLayer(markersGroup[key]);
                });
            } else {
                // ถ้าเลือกความเสี่ยงเจาะจง ให้แสดงเฉพาะอันที่เลือก
                if (markersGroup[selectedRisk]) {
                    map.addLayer(markersGroup[selectedRisk]);
                }
            }
        };

        // Fly to specific coordinates on click
        function focusMap(lat, lng) {
            map.flyTo([lat, lng], 16, {
                animate: true,
                duration: 1.5
            });
        }

        // Sidebar activation logic
        const sidebar = document.getElementById('main-sidebar');

        window.activateSidebar = function() {
            if (sidebar.classList.contains('expanded')) return;

            // Expand sidebar layout (เก็บ hover effect ไว้ เพื่อให้โปร่งแสงตอนเลื่อนเมาส์ออก และชัดขึ้นเมื่อชี้)
            sidebar.classList.add('expanded');
            sidebar.classList.remove('w-16', 'cursor-pointer');
            sidebar.classList.add('w-80', 'h-[calc(100vh-6rem)]');
            
            // Allow content to be visible
            const content = document.getElementById('sidebar-content');
            setTimeout(() => {
                content.style.opacity = '1';
                content.style.pointerEvents = 'auto';
            }, 100);

            // Shift Stats Container to the right to avoid overlapping
            const statsContainer = document.getElementById('stats-container');
            if (statsContainer) {
                statsContainer.classList.remove('left-[5rem]');
                statsContainer.classList.add('left-[22rem]');
            }

            // Remove click listener so it doesn't re-trigger
            sidebar.onclick = null;
        }

        window.deactivateSidebar = function() {
            // Hide content first
            const content = document.getElementById('sidebar-content');
            content.style.opacity = '0';
            content.style.pointerEvents = 'none';

            // Collapse sidebar layout
            sidebar.classList.remove('expanded');
            sidebar.classList.remove('w-80', 'h-[calc(100vh-6rem)]');
            sidebar.classList.add('w-16', 'cursor-pointer');
            
            // Restore Stats Container position
            const statsContainer = document.getElementById('stats-container');
            if (statsContainer) {
                statsContainer.classList.remove('left-[22rem]');
                statsContainer.classList.add('left-[5rem]');
            }

            // Re-add listener after transition
            setTimeout(() => {
                sidebar.onclick = activateSidebar;
            }, 400);
        }

        // MODAL LOGIC (เปิด/ปิดหน้าต่างเข้าสู่ระบบ)
        window.openLoginModal = function() {
            const modal = document.getElementById('login-modal');
            const box = document.getElementById('modal-box');
            
            modal.classList.remove('opacity-0', 'pointer-events-none');
            setTimeout(() => {
                box.classList.remove('scale-95', 'opacity-0');
            }, 50);
        }

        window.closeLoginModal = function() {
            const modal = document.getElementById('login-modal');
            const box = document.getElementById('modal-box');
            
            box.classList.add('scale-95', 'opacity-0');
            setTimeout(() => {
                modal.classList.add('opacity-0', 'pointer-events-none');
            }, 300);
        }

        window.closeLoginModalOutside = function(event) {
            // ปิดเมื่อคลิกพื้นที่ด้านนอกกล่อง Modal เท่านั้น
            if (event.target.id === 'login-modal') {
                closeLoginModal();
            }
        }

        window.handleLoginSubmit = function(event) {
            event.preventDefault(); // ป้องกันการ reload หน้า
            
            // ปิด Modal
            closeLoginModal();

            // เคลียร์ค่าข้อมูลในฟอร์ม
            document.getElementById('login-form').reset();

            // แสดงหน้าจอสำเร็จ (Success Toast Notification) แทนการใช้ alert
            const toast = document.getElementById('success-toast');
            toast.classList.remove('opacity-0', 'pointer-events-none', 'translate-y-[-20px]');
            toast.classList.add('opacity-100', 'translate-y-0');

            // ซ่อน Toast หลังจากผ่านไป 3 วินาที
            setTimeout(() => {
                toast.classList.add('opacity-0', 'pointer-events-none', 'translate-y-[-20px]');
                toast.classList.remove('opacity-100', 'translate-y-0');
            }, 4000);
        }

        window.onload = function() {
            updateDateText();
            initMap();
            renderCommunities();
            
            // Update time every minute
            setInterval(updateDateText, 60000);
        };
    </script>
</body>
</html>