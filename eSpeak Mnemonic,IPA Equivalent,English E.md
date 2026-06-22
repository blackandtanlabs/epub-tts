eSpeak Mnemonic,IPA Equivalent,English Example,Sound
i:,/iː/,"eet, fleece",long 'e'
I,/ɪ/,"it, kit",short 'i'
eI,/eɪ/,"ate, face",long 'a' (diphthong)
E,/ɛ/,"et, dress",short 'e'
a,/æ/,"at, trap",short 'a'
A:,/ɑː/,"arm, palm",open 'a'
O:,/ɔː/,"aut, thought",open 'o'
oU,/oʊ/,"oat, goat",long 'o' (diphthong)
U,/ʊ/,"put, foot",short 'oo'
u:,/uː/,"ooze, goose",long 'oo'
V,/ʌ/,"up, strut",short 'u'
@,/ə/,"about, comma",schwa (unstressed)
aI,/aɪ/,"ice, price",'eye' (diphthong)
aU,/aʊ/,"out, mouth",'ow' (diphthong)
OI,/ɔɪ/,"oil, choice",'oy' (diphthong)

eSpeak Mnemonic,IPA Equivalent,English Example,Sound
"p, b","/p/, /b/","pat, bat",
"t, d","/t/, /d/","time, dime",
"k, g","/k/, /g/","cat, go",
"f, v","/f/, /v/","fan, van",
"T, D","/θ/, /ð/","thin, this",'th' (unvoiced/voiced)
"s, z","/s/, /z/","see, zoo",
"S, Z","/ʃ/, /ʒ/","show, pleasure",'sh' / 'zh'
"tS, dZ","/tʃ/, /dʒ/","chair, jam",'ch' / 'j'
"m, n, N","/m/, /n/, /ŋ/","me, no, sing",
"l, r","/l/, /r/","light, right",
h,/h/,home,
"j, w","/j/, /w/","yes, we",'y' / 'w'

Symbol,Meaning,Example
',Primary Stress: Marks the main stressed syllable.,k'at (cat)
",",Secondary Stress: Marks a lesser stressed syllable.,"k@m,p'u:t (compute)"
_,Short Pause: Acts as a boundary/word separator.,h'El@U_w'3:ld (hello world)

To force Piper to say the word "hello" (h@´loU in a common eSpeak representation) by phonemes, you would typically provide the string:
[[h′El@U]]

    h: the 'h' sound

    'E: the short 'e' sound, with primary stress

    l: the 'l' sound

    @: the schwa sound

    U: the short 'oo' sound

This level of detail (including stress marks) is often necessary when directly feeding phonemes to the model to ensure natural pronunciation.

Use eSpeak-ng to look up a word's phonemes: If you aren't sure of the phonemes for a word, you can ask eSpeak-ng to show them using the -x flag:
Bash

espeak-ng -x "speak"

Output: (You'd see something similar to) s p 'i: k

Use the Phonemes Directly: Once you have the phonemes, you can feed them back using the [[...]] notation to ensure that only those specific sounds are generated, bypassing any potential internal dictionary lookups.