<?php
// === 초기화 및 상태 설정 ===
// 모든 페이지에서 사용자 정보를 공유하기 위해 세션을 시작합니다.
session_start();

// 세션에 'cno'가 있는지 확인하여 로그인 상태를 결정합니다.
$is_logged_in = isset($_SESSION['cno']);

// 관리자('c0') 여부를 확인하여 마이페이지 URL을 동적으로 설정합니다.
// 이를 통해 헤더의 '마이페이지' 링크가 일반 사용자와 관리자에게 다르게 표시됩니다.
$is_admin = $is_logged_in && $_SESSION['cno'] === 'c0';
$mypage_url = $is_admin ? 'admin.php' : 'mypage.php';

// 페이지 타이틀 설정 (브라우저 탭에 표시)
$page_title = '세계를 향한 당신의 날개';

// 공통 헤더를 불러와 페이지 상단을 구성합니다.
// $is_logged_in, $mypage_url 같은 변수들이 header.php 내부에서 사용됩니다.
include 'templates/header.php';
?>

<main class="main-page">
    <!-- 메인 비주얼 영역: 항공기 이미지 배경 -->
    <section class="airplane">
        <div class="airplane-overlay">
            <h1 style="font-size: 2.5rem; margin-bottom: 1rem">세계를 향한 당신의 날개</h1>
            <p style="font-size: 1.2rem; margin-bottom: 2rem">최고의 서비스로 잊지 못할 여행을 선사합니다.</p>
        </div>
    </section>

    <!-- 항공권 검색 위젯 영역 -->
    <div class="container booking-container">
        <div class="booking-widget">
            <div class="widget-tabs">
                <div class="tab-item active">항공권 예매</div>
            </div>
            <div class="booking-content">
                <!-- 사용자가 입력한 검색 조건을 'reserve.php'로 GET 방식으로 전송하는 폼 -->
                <form action="reserve.php" method="GET" class="booking-form">
                    <div class="flight-route">
                        <!-- 출발지 선택 영역 (JavaScript로 제어) -->
                        <div id="departure-selector" class="airport">
                            <div class="code" data-code="">From</div>
                            <div class="name">출발지</div>
                        </div>
                        <!-- 출발지-도착지 교체 아이콘 -->
                        <div id="swap-icon" class="swap-icon"><i class="fa-solid fa-right-left"></i></div>
                        <!-- 도착지 선택 영역 (JavaScript로 제어) -->
                        <div id="arrival-selector" class="airport">
                            <div class="code" data-code="">To</div>
                            <div class="name">도착지</div>
                        </div>
                    </div>

                    <div class="form-field">
                        <label for="date">출발일</label>
                        <!-- PHP를 사용해 페이지 로드 시 오늘 날짜를 기본값으로 설정 -->
                        <input type="date" id="date" name="date" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="form-field">
                        <label for="passenger">탑승객</label>
                        <!-- 현재 기능에서는 고정값이므로 disabled 처리 -->
                        <input type="text" id="passenger" value="성인 1명" disabled>
                        <i class="fa-regular fa-user"></i>
                    </div>

                    <div class="form-field">
                        <label>좌석 등급</label>
                        <div class="radio-group">
                            <div>
                                <input type="radio" id="economy" name="seat_class" value="Economy" checked>
                                <label for="economy">이코노미</label>
                            </div>
                            <div>
                                <input type="radio" id="business" name="seat_class" value="Business">
                                <label for="business">비즈니스</label>
                            </div>
                        </div>
                    </div>

                    <!--
                        사용자에게는 보이지 않지만, 폼 전송 시 실제 공항 코드를 담는 필드.
                        JavaScript를 통해 선택된 공항 코드가 이 필드의 value에 채워집니다.
                    -->
                    <input type="hidden" id="departure-input" name="departure" value="">
                    <input type="hidden" id="arrival-input" name="arrival" value="">

                    <!-- 이 버튼을 클릭하면 form 데이터가 'reserve.php'로 전송됩니다. -->
                    <button type="submit" class="search-btn">항공편 검색</button>
                </form>
            </div>
        </div>
    </div>
</main>

<!-- 공항 선택 모달: 평소에는 숨겨져 있다가 출발/도착지 클릭 시 활성화됩니다. -->
<div id="airport-modal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modal-title" class="modal-title">공항 선택</h2>
            <button class="close-modal-btn">×</button>
        </div>
        <div class="modal-body">
            <!-- JavaScript가 'api/get_airports.php'에서 받아온 공항 목록을 여기에 채워넣습니다. -->
            <ul id="airport-list" class="airport-list"></ul>
        </div>
    </div>
</div>

<?php
// 공통 푸터를 불러와 페이지 하단을 구성합니다.
include 'templates/footer.php';
?>

<!-- 페이지의 동적인 기능을 담당하는 JavaScript 코드 -->
<script>
    // DOM 콘텐츠가 모두 로드된 후 스크립트가 실행되도록 보장합니다.
    document.addEventListener('DOMContentLoaded', function () {
        // --- 전역 변수 및 DOM 요소 참조 설정 ---
        const modal = document.getElementById('airport-modal');
        const closeModalBtn = modal.querySelector('.close-modal-btn');
        const modalTitle = document.getElementById('modal-title');
        const airportList = document.getElementById('airport-list');

        const departureSelector = document.getElementById('departure-selector');
        const arrivalSelector = document.getElementById('arrival-selector');
        const swapIcon = document.getElementById('swap-icon');

        // 폼 제출 시 사용될 hidden input 필드
        const departureInput = document.getElementById('departure-input');
        const arrivalInput = document.getElementById('arrival-input');

        // 현재 모달이 '출발지'를 위한 것인지, '도착지'를 위한 것인지 추적하는 변수
        let currentAirportTarget = null;

        // --- 함수 정의 ---

        /**
         * @function loadAirports
         * @description 서버 API(get_airports.php)를 호출하여 공항 목록을 비동기적으로 가져와
         *              모달 창의 리스트(ul)에 동적으로 추가합니다.
         */
        function loadAirports() {
            fetch('api/get_airports.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        airportList.innerHTML = ''; // 이전 목록 초기화
                        data.airports.forEach(airport => {
                            const airportName = getAirportName(airport.AIRPORT_CODE);
                            const li = document.createElement('li');
                            // 데이터셋(dataset) 속성을 사용하여 각 항목에 공항 코드와 이름을 저장합니다.
                            li.dataset.code = airport.AIRPORT_CODE;
                            li.dataset.name = airportName;
                            li.textContent = `${airportName} (${airport.AIRPORT_CODE})`;
                            airportList.appendChild(li);
                        });
                    }
                })
                .catch(error => console.error('Error loading airports:', error));
        }

        /**
         * @function getAirportName
         * @description 공항 코드를 받아 매핑된 한글 이름을 반환합니다.
         * @param {string} code - 공항 코드 (e.g., 'ICN')
         * @returns {string} - 매핑된 공항 이름 (e.g., '서울/인천')
         */
        function getAirportName(code) {
            // DB에 공항 이름이 없는 경우를 대비한 임시 데이터 매핑
            const names = {
                'ICN': '서울/인천',
                'NRT': '도쿄/나리타',
                'JFK': '뉴욕/존 F.케네디',
                'SYD': '시드니/킹즈퍼드 스미스'
                // 필요한 공항을 계속 추가...
            };
            return names[code] || code; // 매핑된 이름이 없으면 코드를 그대로 반환
        }

        /**
         * @function openModal
         * @description 공항 선택 모달을 엽니다.
         * @param {HTMLElement} target - 모달을 연 요소 (departureSelector 또는 arrivalSelector)
         * @param {string} title - 모달의 제목 (e.g., '출발 공항 선택')
         */
        function openModal(target, title) {
            // 사용 편의성을 위해, 반대편에 이미 선택된 공항은 모달 리스트에서 비활성화 처리합니다.
            const otherAirportCode = (target.id === 'departure-selector')
                ? arrivalSelector.querySelector('.code').dataset.code
                : departureSelector.querySelector('.code').dataset.code;

            const airportItems = airportList.querySelectorAll('li');
            airportItems.forEach(item => {
                // 반대편 공항과 코드가 같으면 'disabled' 클래스를 추가하여 클릭 불가능하게 만듭니다.
                if (item.dataset.code && item.dataset.code === otherAirportCode) {
                    item.style.pointerEvents = 'none';
                    item.style.opacity = '0.5';
                } else {
                    item.style.pointerEvents = 'auto';
                    item.style.opacity = '1';
                }
            });

            currentAirportTarget = target; // 어느 쪽(출발/도착)을 선택 중인지 기록
            modalTitle.textContent = title;
            modal.classList.add('active'); // CSS를 통해 모달을 화면에 표시
        }
        
        /**
         * @function closeModal
         * @description 공항 선택 모달을 닫습니다.
         */
        const closeModal = () => {
            modal.classList.remove('active'); // CSS를 통해 모달을 숨김
        };

        // --- 이벤트 리스너 등록 ---

        // 출발지 또는 도착지 영역을 클릭하면 모달이 열립니다.
        departureSelector.addEventListener('click', () => openModal(departureSelector, '출발 공항 선택'));
        arrivalSelector.addEventListener('click', () => openModal(arrivalSelector, '도착 공항 선택'));

        // 모달의 'X' 버튼이나 모달 바깥 영역을 클릭하면 모달이 닫힙니다.
        closeModalBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', (e) => {
            if (e.target === modal) { // 클릭된 요소가 모달 배경(overlay) 자체일 때만 닫기
                closeModal();
            }
        });

        // 모달의 공항 리스트에서 특정 항목(li)을 클릭했을 때의 동작
        airportList.addEventListener('click', (event) => {
            if (event.target.tagName === 'LI' && !event.target.classList.contains('disabled')) {
                const code = event.target.dataset.code;
                const name = event.target.dataset.name;

                if (currentAirportTarget) {
                    // 1. 화면에 보이는 텍스트(코드와 이름)를 업데이트합니다.
                    const codeDiv = currentAirportTarget.querySelector('.code');
                    codeDiv.textContent = code;
                    codeDiv.dataset.code = code; // 나중을 위해 데이터 속성도 업데이트
                    currentAirportTarget.querySelector('.name').textContent = name;

                    // 2. 폼 전송을 위해 숨겨진(hidden) input의 값을 업데이트합니다.
                    if (currentAirportTarget.id === 'departure-selector') {
                        departureInput.value = code;
                    } else {
                        arrivalInput.value = code;
                    }
                }
                closeModal(); // 선택 완료 후 모달 닫기
            }
        });

        // 출발지와 도착지를 서로 바꾸는 'swap' 아이콘 클릭 이벤트
        swapIcon.addEventListener('click', () => {
            // 각 선택자의 UI 요소에서 현재 값들을 가져옵니다.
            const depCodeElem = departureSelector.querySelector('.code');
            const depNameElem = departureSelector.querySelector('.name');
            const arrCodeElem = arrivalSelector.querySelector('.code');
            const arrNameElem = arrivalSelector.querySelector('.name');

            // 도착지가 선택되지 않은 상태에서는 교환 로직을 실행하지 않습니다.
            if (!arrCodeElem.dataset.code) return;

            // 임시 변수를 사용하여 값들을 서로 교환합니다 (UI 텍스트, 데이터 속성).
            const tempCode = depCodeElem.dataset.code;
            const tempName = depNameElem.textContent;

            depCodeElem.textContent = arrCodeElem.dataset.code;
            depCodeElem.dataset.code = arrCodeElem.dataset.code;
            depNameElem.textContent = arrNameElem.textContent;

            arrCodeElem.textContent = tempCode;
            arrCodeElem.dataset.code = tempCode;
            arrNameElem.textContent = tempName;

            // 폼 전송에 사용될 hidden input의 값도 교환합니다.
            departureInput.value = depCodeElem.dataset.code;
            arrivalInput.value = arrCodeElem.dataset.code;
        });

        // --- 초기화 함수 실행 ---
        // 페이지가 처음 로드될 때, 모달에 표시할 공항 목록을 미리 불러옵니다.
        loadAirports();
    });
</script>
</body>

</html>