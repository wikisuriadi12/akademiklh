<?php
Auth::logout();
session_start();
flash('Anda sudah keluar.');
redirect(Host::masuk());
