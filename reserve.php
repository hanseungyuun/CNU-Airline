<?php
/**
 * @file reserve.php
 * @brief 항공권 검색 결과를 표시하고, 재검색 및 정렬 기능을 제공하는 페이지
 * @description
 * 1. (초기 로드) main.php에서 받은 검색 조건으로 항공편을 조회하여 서버사이드에서 렌더링합니다.
 * 2. (사용자 인터랙션) 페이지 내에서 검색 조건이나 정렬 기준 변경 시,
 *    JavaScript(fetch)를 통해 api/search_flights.php를 호출하고 결과를 비동기적으로 업데이트합니다.
 */

// --- 세션 및 사용자 상태 설정 ---
session_start();
$is_logged_in = isset($_SESSION['cno']);
$is_admin = $is_logged_in && $_SESSION['cno'] === 'c0';
$mypage_url = $is_admin ? 'admin.php' : 'mypage.php';

// --- GET 파라미터 처리 ---
// main.php의 검색 폼에서 전송된 값을 받아 변수에 저장합니다.
$departure = isset($_GET['departure']) ? htmlspecialchars($_GET['departure']) : '';
$arrival = isset($_GET['arrival']) ? htmlspecialchars($_GET['arrival']) : '';
$date = isset($_GET['date']) ? htmlspecialchars($_GET['date']) : date('Y-m-d');
$seat_class = isset($_GET['seat_class']) ? htmlspecialchars($_GET['seat_class']) : 'Economy';

// --- 초기 데이터 조회 (Server-Side Rendering) ---
$initial_flights = [];
// 출발지와 도착지가 모두 지정된 경우에만, 페이지 첫 로드 시 DB에서 항공편을 조회합니다.
if (!empty($departure) && !empty($arrival)) {
    require_once 'api/db_connect.php';
    $conn = null;
    try {
        $conn = new PDO($dsn, $db_username, $db_password);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // JavaScript의 비동기 검색(search_flights.php)과 동일한 쿼리를 사용하여 일관성을 유지합니다.
        $sql = "SELECT
                    A.AIRLINE, A.FLIGHTNO,
                    TO_CHAR(A.DEPARTUREDATETIME, 'YYYY-MM-DD\"T\"HH24:MI:SS') AS DEPARTUREDATETIME,
                    TO_CHAR(A.ARRIVALDATETIME, 'YYYY-MM-DD\"T\"HH24:MI:SS') AS ARRIVALDATETIME,
                    A.DEPARTUREAIRPORT, A.ARRIVALAIRPORT,
                    S.SEATCLASS, S.PRICE,
                    GET_REMAINING_SEATS(A.FLIGHTNO, A.DEPARTUREDATETIME, S.SEATCLASS) AS REMAINING_SEATS
                FROM AIRPLANE A
                JOIN SEATS S ON A.FLIGHTNO = S.FLIGHTNO AND A.DEPARTUREDATETIME = S.DEPARTUREDATETIME
                WHERE
                    A.DEPARTUREAIRPORT = :departure
                    AND A.ARRIVALAIRPORT = :arrival
                    AND TRUNC(A.DEPARTUREDATETIME) = TO_DATE(:flight_date, 'YYYY-MM-DD')
                    AND S.SEATCLASS = :seat_class
                ORDER BY S.PRICE ASC"; // 초기 정렬은 가격순
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':departure' => $departure,
            ':arrival' => $arrival,
            ':flight_date' => $date,
            ':seat_class' => $seat_class
        ]);
        $initial_flights = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // 오류 발생 시, 사용자에게는 빈 화면을 보여주고 서버 로그에만 기록합니다.
        error_log("Initial flight search failed: " . $e->getMessage());
    } finally {
        $conn = null;
    }
}

$page_title = '항공권 조회';
include 'templates/header.php';
?>

<main class="content-main">
    <div class="container booking-container">
        <!-- 검색 조건 재설정 폼 -->
        <div class="content-panel">
            <h2 class="panel-title">항공권 예매</h2>
            <form id="search-form" action="reserve.php" method="GET" class="booking-form">
                <div class="flight-route">
                    <!-- PHP 변수를 사용해 main.php에서 선택한 검색 조건을 그대로 표시합니다. -->
                    <div id="departure-selector" class="airport">
                        <div class="code" data-code="<?= $departure ?>"><?= $departure ?: 'From' ?></div>
                        <div class="name">출발지</div>
                    </div>
                    <div id="swap-icon" class="swap-icon"><i class="fa-solid fa-right-left"></i></div>
                    <div id="arrival-selector" class="airport">
                        <div class="code" data-code="<?= $arrival ?>"><?= $arrival ?: 'To' ?></div>
                        <div class="name">도착지</div>
                    </div>
                </div>
                <div class="form-field">
                    <label for="date">출발일</label>
                    <input type="date" id="date" name="date" value="<?= $date ?>" required>
                </div>
                <div class="form-field">
                    <label for="passenger">탑승객</label>
                    <input type="text" id="passenger" value="성인 1명" disabled>
                    <i class="fa-regular fa-user"></i>
                </div>
                <div class="form-field">
                    <label>좌석 등급</label>
                    <div class="radio-group">
                        <div>
                            <!-- PHP 삼항 연산자를 이용해 현재 좌석 등급에 맞는 라디오 버튼을 'checked' 상태로 만듭니다. -->
                            <input type="radio" id="economy" name="seat_class" value="Economy" <?= ($seat_class === 'Economy') ? 'checked' : '' ?>>
                            <label for="economy">이코노미</label>
                        </div>
                        <div>
                            <input type="radio" id="business" name="seat_class" value="Business" <?= ($seat_class === 'Business') ? 'checked' : '' ?>>
                            <label for="business">비즈니스</label>
                        </div>
                    </div>
                </div>
                <input type="hidden" id="departure-input" name="departure" value="<?= $departure ?>">
                <input type="hidden" id="arrival-input" name="arrival" value="<?= $arrival ?>">
                <button type="submit" class="search-btn">항공편 검색</button>
            </form>
        </div>

        <!-- 항공권 조회 결과 테이블 -->
        <div class="content-panel results-container">
            <div class="results-header">
                <h2 class="panel-title">항공권 조회</h2>
                <div class="sort-options">
                    <!-- 정렬 버튼. 'active' 클래스로 현재 정렬 기준을 표시합니다. -->
                    <button data-sort="price" class="active">요금순</button>
                    <button data-sort="time">출발시간순</button>
                </div>
            </div>
            <table class="results-table">
                <thead>
                    <tr>
                        <th>항공편 정보</th>
                        <th>출발</th>
                        <th>도착</th>
                        <th>요금</th>
                        <th>남은 좌석</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="flights-tbody">
                    <?php // --- 서버에서 렌더링한 초기 항공편 목록 출력 --- ?>
                    <?php if (count($initial_flights) > 0): ?>
                        <?php foreach ($initial_flights as $flight): ?>
                            <tr>
                                <td class="flight-info">
                                    <div class="airline"><?= htmlspecialchars($flight['AIRLINE']) ?></div>
                                    <div class="flight-num"><?= htmlspecialchars($flight['FLIGHTNO']) ?></div>
                                </td>
                                <td class="time-info">
                                    <div><?= (new DateTime($flight['DEPARTUREDATETIME']))->format('H:i') ?></div>
                                    <div><?= htmlspecialchars($flight['DEPARTUREAIRPORT']) ?></div>
                                </td>
                                <td class="time-info">
                                    <div><?= (new DateTime($flight['ARRIVALDATETIME']))->format('H:i') ?></div>
                                    <div><?= htmlspecialchars($flight['ARRIVALAIRPORT']) ?></div>
                                </td>
                                <td class="price-info"><?= number_format($flight['PRICE']) ?> 원</td>
                                <td><?= htmlspecialchars($flight['REMAINING_SEATS']) ?> 석</td>
                                <td>
                                    <!-- '예약' 버튼 클릭 시, 선택한 항공편의 주요 정보를 POST 방식으로 payment.php에 전송합니다. -->
                                    <form action="payment.php" method="POST">
                                        <input type="hidden" name="flightno" value="<?= htmlspecialchars($flight['FLIGHTNO']) ?>">
                                        <input type="hidden" name="departureDateTime" value="<?= htmlspecialchars($flight['DEPARTUREDATETIME']) ?>">
                                        <input type="hidden" name="seatClass" value="<?= htmlspecialchars($flight['SEATCLASS']) ?>">
                                        <button type="submit" class="reserve-flight-btn">예약</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php // main.php에서 검색을 하고 온 경우에만 "결과 없음" 메시지를 표시합니다.
                            if (!empty($departure) && !empty($arrival)): ?>
                            <tr>
                                <td colspan="6">검색 결과가 없습니다. 다른 조건을 선택해주세요.</td>
                            </tr>
                        <?php endif; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php include 'templates/footer.php'; ?>

<!-- 공항 선택 모달 HTML (main.php와 동일) -->
<div id="airport-modal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modal-title" class="modal-title">공항 선택</h2>
            <button class="close-modal-btn">×</button>
        </div>
        <div class="modal-body">
            <ul id="airport-list" class="airport-list"></ul>
        </div>
    </div>
</div>

<!-- 페이지 동적 기능을 위한 JavaScript -->
<script>
    document.addEventListener('DOMContentLoaded', () => {
        // --- DOM 요소 참조 ---
        const searchForm = document.getElementById('search-form');
        const flightsTbody = document.getElementById('flights-tbody');
        const sortButtons = document.querySelectorAll('.sort-options button');

        // --- 공항 선택 모달 및 Swap 로직 (main.php와 동일) ---
        const modal = document.getElementById('airport-modal');
        const closeModalBtn = modal.querySelector('.close-modal-btn');
        const modalTitle = document.getElementById('modal-title');
        const airportList = document.getElementById('airport-list');

        const departureSelector = document.getElementById('departure-selector');
        const arrivalSelector = document.getElementById('arrival-selector');
        const swapIcon = document.getElementById('swap-icon');

        const departureInput = document.getElementById('departure-input');
        const arrivalInput = document.getElementById('arrival-input');

        let currentAirportTarget = null;

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

        const closeModal = () => {
            modal.classList.remove('active'); // CSS를 통해 모달을 숨김
        };

        departureSelector.addEventListener('click', () => openModal(departureSelector, '출발 공항 선택'));
        arrivalSelector.addEventListener('click', () => openModal(arrivalSelector, '도착 공항 선택'));

        closeModalBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', (e) => {
            if (e.target === modal) { // 클릭된 요소가 모달 배경(overlay) 자체일 때만 닫기
                closeModal();
            }
        });

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

        /**
         * @function fetchAndDisplayFlights
         * @brief api/search_flights.php를 호출하여 항공편을 비동기적으로 검색하고 결과를 테이블에 렌더링합니다.
         */
        function fetchAndDisplayFlights() {
            // 현재 폼에 입력된 값들을 가져옵니다.
            const departure = document.getElementById('departure-input').value;
            const arrival = document.getElementById('arrival-input').value;
            const date = document.getElementById('date').value;
            const seat_class = document.querySelector('input[name="seat_class"]:checked').value;
            // 활성화된 정렬 버튼의 data-sort 속성 값을 가져옵니다.
            const sort = document.querySelector('.sort-options button.active').dataset.sort;

            // URLSearchParams 객체를 사용해 API 요청을 위한 쿼리 스트링을 안전하게 생성합니다.
            const params = new URLSearchParams({ departure, arrival, date, seat_class, sort });

            // 검색 시작을 알리는 메시지를 테이블에 표시합니다.
            flightsTbody.innerHTML = '<tr><td colspan="6">검색 중...</td></tr>';

            // Fetch API를 사용해 백엔드에 항공편 데이터를 요청합니다.
            fetch(`api/search_flights.php?${params.toString()}`)
                .then(response => response.json())
                .then(data => {
                    flightsTbody.innerHTML = ''; // 기존 테이블 내용 초기화
                    if (data.success && data.flights.length > 0) {
                        // 성공적으로 데이터를 받아오면, 각 항공편에 대해 테이블 행(row)을 생성합니다.
                        data.flights.forEach(flight => {
                            const row = document.createElement('tr');
                            const formatTime = (datetime) => new Date(datetime).toLocaleTimeString('ko-KR', { hour: '2-digit', minute: '2-digit', hour12: false });

                            // 서버사이드 렌더링과 동일한 HTML 구조를 동적으로 생성합니다.
                            row.innerHTML = `
                                <td class="flight-info">
                                    <div class="airline">${flight.AIRLINE}</div>
                                    <div class="flight-num">${flight.FLIGHTNO}</div>
                                </td>
                                <td class="time-info">
                                    <div>${formatTime(flight.DEPARTUREDATETIME)}</div>
                                    <div>${flight.DEPARTUREAIRPORT}</div>
                                </td>
                                <td class="time-info">
                                    <div>${formatTime(flight.ARRIVALDATETIME)}</div>
                                    <div>${flight.ARRIVALAIRPORT}</div>
                                </td>
                                <td class="price-info">${Number(flight.PRICE).toLocaleString()} 원</td>
                                <td>${flight.REMAINING_SEATS} 석</td>
                                <td>
                                    <form action="payment.php" method="POST">
                                        <input type="hidden" name="flightno" value="${flight.FLIGHTNO}">
                                        <input type="hidden" name="departureDateTime" value="${flight.DEPARTUREDATETIME}">
                                        <input type="hidden" name="seatClass" value="${flight.SEATCLASS}">
                                        <button type="submit" class="reserve-flight-btn">예약</button>
                                    </form>
                                </td>
                            `;
                            flightsTbody.appendChild(row);
                        });
                    } else {
                        // 검색 결과가 없는 경우 메시지를 표시합니다.
                        flightsTbody.innerHTML = `<tr><td colspan="6">${data.message || '검색 결과가 없습니다.'}</td></tr>`;
                    }
                })
                .catch(error => {
                    // 네트워크 오류 등 API 호출 실패 시 에러 메시지를 표시합니다.
                    console.error('Flight search fetch error:', error);
                    flightsTbody.innerHTML = '<tr><td colspan="6">데이터를 불러오는 데 실패했습니다.</td></tr>';
                });
        }

        // --- 이벤트 리스너 등록 ---

        // 정렬 버튼(요금순, 출발시간순) 클릭 이벤트
        sortButtons.forEach(button => {
            button.addEventListener('click', () => {
                sortButtons.forEach(btn => btn.classList.remove('active'));
                button.classList.add('active');
                // 정렬 기준 변경 후, 현재 검색 조건으로 다시 항공편을 조회합니다.
                fetchAndDisplayFlights();
            });
        });

        // 검색 폼 제출 이벤트 처리
        searchForm.addEventListener('submit', (e) => {
            // 기본 폼 제출 동작(페이지 새로고침)을 막습니다.
            e.preventDefault();
            // 대신, 비동기 검색 함수를 호출합니다.
            fetchAndDisplayFlights();
        });

        // 페이지 로드 시, 모달에 사용할 공항 목록을 미리 불러옵니다.
        loadAirports();
    });
</script>
<style>
    /* 이 페이지에서만 사용되는 임시 스타일 (모달의 disabled 항목) */
    .airport-list li.disabled {
        color: #ccc;
        cursor: not-allowed;
    }
</style>
</body>

</html>