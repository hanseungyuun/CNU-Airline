<?php
/**
 * @file api/logout.php
 * @brief 로그아웃을 위한 API
 * @description 로그인 세션을 삭제하고, 메인 페이지로 리디렉션합니다.
 */
session_start();
session_unset();
session_destroy();
header('Location: ../main.php');
exit();
?>