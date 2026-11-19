' Porneste go2rtc (camerele) in fundal, fara fereastra. Daca ruleaza deja, al doilea se opreste singur (portul e ocupat).
Set sh = CreateObject("WScript.Shell")
dir = CreateObject("Scripting.FileSystemObject").GetParentFolderName(WScript.ScriptFullName)
sh.CurrentDirectory = dir
sh.Run Chr(34) & dir & "\go2rtc.exe" & Chr(34) & " -config " & Chr(34) & dir & "\go2rtc.yaml" & Chr(34), 0, False
