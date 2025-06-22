<?php
/**
 * @file api/db_connect.php
 * @brief 데이터베이스 연결에 필요한 정보를 설정하는 파일
 * @description 이 파일을 require_once 하는 모든 스크립트는 아래에 정의된
 *              $dsn, $db_username, $db_password, $options 변수를 사용할 수 있습니다.
 *              DB 연결 정보를 한 곳에서 관리하여 유지보수성을 높입니다.
 */

// --- Oracle 데이터베이스 연결 정보 ---
$db_username = 'd202102724';
$db_password = '1111';
// TNS(Transparent Network Substrate) 정보: Oracle DB의 위치와 서비스 이름을 정의합니다.
$tns = "
(DESCRIPTION=
    (ADDRESS_LIST= (ADDRESS=(PROTOCOL=TCP)(HOST=localhost)(PORT=1521)))
    (CONNECT_DATA= (SERVICE_NAME=XE))
)";

// DSN(Data Source Name): PDO가 어떤 드라이버를 사용하여 어디에 접속할지 정의하는 문자열입니다.
$dsn = "oci:dbname=" . $tns . ";charset=utf8";

// PDO 연결 시 사용할 옵션 배열
$options = [
    // 에러 발생 시, 경고 대신 예외(Exception)를 발생시켜 try-catch로 처리할 수 있게 합니다.
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    // 데이터를 가져올 때(fetch) 기본적으로 연관 배열(key-value) 형태로 가져오도록 설정합니다.
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    // SQL Injection 공격 방지를 위해, PHP의 에뮬레이션 모드를 끄고 DB의 네이티브 PreparedStatement 기능을 사용하도록 강제합니다.
    PDO::ATTR_EMULATE_PREPARES   => false,
];
?>