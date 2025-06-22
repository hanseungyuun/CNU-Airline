<?php
/**
 * @file api/login.php
 * @brief 사용자 로그인 인증을 처리하는 API
 * @description auth.php에서 POST로 전송된 회원번호(ID)와 비밀번호를 받아
 *              데이터베이스의 CUSTOMER 테이블 정보와 비교하여 인증을 수행합니다.
 *              인증 성공 시, 세션에 사용자 정보를 저장합니다.
 */

session_start();
header('Content-Type: application/json');

// --- 1. 입력 값 유효성 검사 ---
// 요청 방식이 POST인지 확인합니다.
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(['success' => false, 'message' => '잘못된 접근 방식입니다.']);
    exit;
}

// POST로 전송된 회원번호(id)와 비밀번호(pw)를 받습니다.
$cno = $_POST['id'] ?? '';
$passwd = $_POST['pw'] ?? '';

// 아이디나 비밀번호가 비어있는지 확인합니다.
if (empty($cno) || empty($passwd)) {
    echo json_encode(['success' => false, 'message' => '아이디와 비밀번호를 모두 입력해주세요.']);
    exit;
}

// --- 2. 데이터베이스 연결 및 인증 처리 ---
require_once 'db_connect.php';
$conn = null;

try {
    // PDO를 사용하여 데이터베이스에 접속합니다.
    $conn = new PDO($dsn, $db_username, $db_password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // SQL Injection 공격을 방지하기 위해 PreparedStatement를 사용합니다.
    $stmt = $conn->prepare("SELECT NAME, PASSWD, EMAIL FROM CUSTOMER WHERE CNO = :cno");
    // 쿼리의 ':cno' 플레이스홀더에 실제 입력받은 $cno 값을 바인딩합니다.
    $stmt->bindParam(':cno', $cno);
    $stmt->execute();

    // 쿼리 결과를 연관 배열 형태로 가져옵니다. 사용자가 없으면 false를 반환합니다.
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // --- 3. 인증 결과 처리 ---
    // 사용자 정보가 존재하고, 입력된 비밀번호가 DB의 비밀번호와 일치하는지 확인합니다.
    if ($user && $passwd === $user['PASSWD']) {
        // 인증 성공: 사용자 정보를 세션 변수에 저장합니다.
        // 이 세션 정보는 다른 페이지에서 로그인 상태를 확인하고 사용자 정보를 활용하는 데 사용됩니다.
        $_SESSION['cno'] = $cno;
        $_SESSION['name'] = $user['NAME'];
        $_SESSION['email'] = $user['EMAIL'];
        $_SESSION['is_admin'] = ($cno === 'c0'); // 회원번호 'c0'을 관리자로 간주

        // 클라이언트에게 성공 상태를 JSON으로 응답합니다.
        echo json_encode(['success' => true]);

    } else {
        // 인증 실패: 사용자 정보가 없거나 비밀번호가 틀린 경우
        echo json_encode(['success' => false, 'message' => '아이디 또는 비밀번호가 일치하지 않습니다.']);
    }

} catch (PDOException $e) {
    // 데이터베이스 관련 오류가 발생한 경우
    // error_log("Login DB Error: " . $e->getMessage()); // 에러 로그를 남기는 것이 좋음
    echo json_encode(['success' => false, 'message' => '데이터베이스 처리 중 오류가 발생했습니다.']);

} finally {
    // 스크립트 실행이 끝나면 데이터베이스 연결을 항상 종료합니다.
    $conn = null;
}
?>