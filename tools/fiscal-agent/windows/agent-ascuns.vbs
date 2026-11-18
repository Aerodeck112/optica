' Porneste agentul casei de marcat in fundal (fara fereastra). Daca ruleaza deja, noul agent se opreste singur.
Set sh = CreateObject("WScript.Shell")
dir = CreateObject("Scripting.FileSystemObject").GetParentFolderName(WScript.ScriptFullName)
sh.CurrentDirectory = dir
q = Chr(34)
cmd = q & dir & "\php\php.exe" & q & " -c " & q & dir & "\php\php.ini" & q & _
      " -d extension_dir=" & q & dir & "\php\ext" & q & _
      " -d curl.cainfo=" & q & dir & "\php\cacert.pem" & q & _
      " -d openssl.cafile=" & q & dir & "\php\cacert.pem" & q & _
      " " & q & dir & "\aw-fiscal-agent.php" & q
sh.Run cmd, 0, False
