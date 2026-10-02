-- Trainingsapp seed data
-- Dataset: 1.0.0
-- Source: Sporten.html
-- Contains content only. No production user is created here.

SET NAMES utf8mb4;
SET time_zone = '+00:00';
START TRANSACTION;

INSERT INTO app_meta (meta_key, meta_value) VALUES
  ('dataset_version', '1.0.0'),
  ('source_file', 'Sporten.html'),
  ('source_sha256', 'd473f2558df3913611fc6bf60031be6881d4a33074018c131764ddd2173b12de');

INSERT INTO programs (id, public_id, code, name, version, description, status) VALUES
  (1, '3V5FV62M51FNCP7MEBFSJWJBAY', 'persoonlijk-14-weken-schema', 'Persoonlijk 14-weken trainingsprogramma', '1.0', 'Het bestaande trainingsschema uit Sporten.html, gestructureerd in vijf trainingsblokken met zes workouts per cyclus.', 'active');

INSERT INTO training_blocks (id, public_id, program_id, name, sequence, original_week_start, original_week_end, default_cycle_count, description) VALUES
  (1, '0J6147BJFBEFFJK2CH4114053K', 1, 'Trainingsblok 1', 1, 1, 3, 3, NULL),
  (2, '7R2RZQEKKJ3KPWZ9SYEACCZWJ2', 1, 'Trainingsblok 2', 2, 4, 6, 3, NULL),
  (3, '49KD8KTMTQ6VSHF200EKWCX9K8', 1, 'Trainingsblok 3', 3, 7, 9, 3, NULL),
  (4, '0VQKK1XY2ZQDVEGAVCGJ3HJZ4T', 1, 'Trainingsblok 4', 4, 10, 12, 3, NULL),
  (5, '5AW63T8FAVNM3WM13QGAX0Q4A5', 1, 'Trainingsblok 5', 5, 13, 14, 2, NULL);

INSERT INTO workout_templates (id, public_id, code, name, category, category_label, source_category_label, description, instructions, video_url, protocol_type, protocol_config, source_text, content_version, is_active) VALUES
  (1, '1N0NC81PKBT7BZXDZJ8H9ZJNHZ', 'b1-d1-fast-and-sweaty', 'Fast and Sweaty', 'full_body_hiit', 'Full body HIIT', 'Full body HIIT', NULL, 'HIIT staat voor high intensity interval training
Intensieve inspanning met korte pauzes. Je zet de timer op 10 min en maakt zoveel mogelijk rondes van het circuit.', 'https://youtu.be/kNgPYQz8mZA', 'amrap', '{"duration_seconds":600,"round_rest_seconds":60}', 'Full body HIIT (Fast and Sweaty) HIIT staat voor high intensity interval training
Intensieve inspanning met korte pauzes. Je zet de timer op 10 min en maakt zoveel mogelijk rondes van het circuit.
20 jumping jacks
20 plank shoulder taps
20 achterwaartse lunge
20 russian twist
20 air squat
1 minuut rust', 1, 1),
  (2, '56TZTK3MRR13DESKGQ0EVAWR4E', 'b1-d2-leg-day-love', 'Leg Day Love', 'lower_body', 'Lower body', 'Lower body', NULL, 'gebruik gewichten waar mogelijk.', 'https://www.youtube.com/watch?v=gyl8KemvnGE', 'standard', '{}', 'Lower body (Leg Day Love) gebruik gewichten waar mogelijk.
1 squat 3 x 10
2 deadlift 3 x 10
3 reverse lunge 3 x 10
4 glute bridge 3 x 10
5 calf raise 3 x 10', 1, 1),
  (3, '05RED1F9Z1N172Q0SASGKWXF1V', 'b1-d3-core-crusher', 'Core Crusher', 'abs', 'Abs', 'Abs', NULL, 'AMRAP staat voor As many reps as possible
Timer op 5 minuten en zoveel mogelijk rondes van het circuit.', 'https://www.youtube.com/watch?v=ysp-51DY8r0', 'amrap', '{"duration_seconds":300,"round_rest_seconds":60}', 'Abs (Core Crusher) AMRAP staat voor As many reps as possible
Timer op 5 minuten en zoveel mogelijk rondes van het circuit.
20 bird dog
20 cross crunch
20 heel touches
20 bicycle crunch
1 minuut rust', 1, 1),
  (4, '5NDG7SKJFPZTTED175EX2AN48P', 'b1-d4-no-pain-no-gain', 'No Pain No Gain', 'full_body_tabata', 'Full body Tabata', 'Full body Tabata', NULL, '(Tabata muziek op Spotify of Tabata timer downloaden) Tabata is ook een vorm van HIIT
20 sec werk 10 sec rust, 8 herhalingen, en dan door naar de volgende oefening.', 'https://youtu.be/HRDDwL7SmcI', 'tabata', '{"work_seconds":20,"rest_seconds":10,"rounds_per_exercise":8}', 'Full body Tabata (No Pain No Gain) (Tabata muziek op Spotify of Tabata timer downloaden) Tabata is ook een vorm van HIIT
20 sec werk 10 sec rust, 8 herhalingen, en dan door naar de volgende oefening.
Front raise
Squat thruster
Plank shoulder taps
Zijwaartse lunge
Bicep curl', 1, 1),
  (5, '05RDTD50FWQECEGWMBKW4WRM5Q', 'b1-d5-strong-and-steady', 'Strong and Steady', 'upper_body', 'Upper body', 'Upperbody', NULL, 'gebruik zwaardere gewichten waar mogelijk', 'https://www.youtube.com/watch?v=hDGVjQzZPlY', 'standard', '{}', 'Upperbody (Strong and Steady) gebruik zwaardere gewichten waar mogelijk
1 shoulder press 3 x 10
2 tricep dip 3 x 10
3 row 3 x 10
4 chest press 3 x 10
5 bicep curl 3 x 10', 1, 1),
  (6, '20B5KGAB9M0S288VB2DA2TTAPZ', 'b1-d6-weekend-warrior', 'Weekend Warrior', 'full_body', 'Full body', 'Full body', NULL, '3 rondes', 'https://www.youtube.com/watch?v=M6veXtQfWtQ', 'circuit', '{"rounds":3,"round_rest_seconds":60}', 'Full body (Weekend Warrior) 3 rondes
10 squat
10 push-up
10 mountain climbers
10 shoulder taps
10 burpees
1 minuut rust', 1, 1),
  (7, '16W0CX7VNSAR4KHRP065A1QZV2', 'b2-d1-sweat-session', 'Sweat Session', 'full_body_hiit', 'Full body HIIT', 'Full body HIIT', NULL, 'timer op 10 min en zoveel mogelijk rondes', 'https://youtu.be/45gZofrtc78', 'amrap', '{"duration_seconds":600,"round_rest_seconds":60}', 'Full body HIIT (Sweat Session) timer op 10 min en zoveel mogelijk rondes
20 jumping jacks
20 plank shoulder taps
20 reverse lunge
20 russian twist
20 air squat
1 minuut rust', 1, 1),
  (8, '0SANEQZ9XHJ0XN2K5GMGMW6182', 'b2-d2-glutes-and-gains', 'Glutes and Gains', 'lower_body', 'Lower body', 'Lower body', NULL, 'waar mogelijk verzwaren met gewichten', 'https://youtu.be/Ba-JbqH8bQE', 'standard', '{}', 'Lower body (Glutes and Gains) waar mogelijk verzwaren met gewichten
1 squat 3 x 10
2 deadlift 3 x 10
3 zijwaartse lunge 3 x 10
4 calf raise 3 x 10
5 glute bridge 3 x 10', 1, 1),
  (9, '60EVRXDGV1ENZNT06A3VYMX76A', 'b2-d3-core-control', 'Core Control', 'abs', 'Abs', 'Abs', NULL, 'timer op 5 min en zoveel mogelijk rondes', 'https://youtu.be/iFyGjBFdiwc', 'amrap', '{"duration_seconds":300,"round_rest_seconds":60}', 'Abs (Core Control) timer op 5 min en zoveel mogelijk rondes
20 bird dog
20 cross crunch
20 heel touches
20 bicycle crunch
1 minuut rust', 1, 1),
  (10, '7Y7EJQ0DE820KD82XM2QND69FH', 'b2-d4-push-through', 'Push Through', 'full_body_tabata', 'Full body Tabata', 'Full body Tabata', NULL, '20 sec werk 10 sec rust, 8 herhalingen, dan door', 'https://youtu.be/MsBXOqax11g', 'tabata', '{"work_seconds":20,"rest_seconds":10,"rounds_per_exercise":8}', 'Full body Tabata (Push Through) 20 sec werk 10 sec rust, 8 herhalingen, dan door
Front raise
Squat thruster
Plank shoulder taps
Zijwaartse lunge
Bicep curl', 1, 1),
  (11, '4E2AH8NZYCF00FNPKCGKNNVKJM', 'b2-d5-arms-on-fire', 'Arms on Fire', 'upper_body', 'Upper body', 'Upperbody', NULL, 'zwaarder waar mogelijk', 'https://youtu.be/vHYXa63zzZk', 'standard', '{}', 'Upperbody (Arms on Fire) zwaarder waar mogelijk
1 shoulder press 3 x 10
2 tricep dip 3 x 10
3 row 3 x 10
4 chest press 3 x 10
5 bicep curl 3 x 10', 1, 1),
  (12, '0TYBE57T4EN716ANJ2RPD73DS7', 'b2-d6-circuit-saturday', 'Circuit Saturday', 'full_body', 'Full body', 'Full body', NULL, '3 rondes', 'https://youtu.be/D8gmgM-Rpuk', 'circuit', '{"rounds":3,"round_rest_seconds":60}', 'Full body (Circuit Saturday) 3 rondes
10 squat
10 push-up
10 mountain climbers
10 shoulder taps
10 burpees
1 minuut rust', 1, 1),
  (13, '5P1AY548WV6G8EEAM2WB3X7ASB', 'b3-d1-hiit-happens', 'HIIT Happens', 'full_body', 'Full body', 'Full body', NULL, 'timer op 10 min en zoveel mogelijk rondes', 'https://youtu.be/i_tPbUdqYp4', 'amrap', '{"duration_seconds":600,"round_rest_seconds":60}', 'Full body (HIIT Happens) timer op 10 min en zoveel mogelijk rondes
10 jumping jacks
10 plank shoulder taps
10 om en om lunge
10 russian twist
10 air squat
1 minuut rust', 1, 1),
  (14, '2B4XGA48QG7JBXV2924AF2K769', 'b3-d2-booty-buster', 'Booty Buster', 'lower_body', 'Lower body', 'Lower body', NULL, 'waar het kan verzwaren met gewichten', 'https://youtu.be/3WRHz20bua4', 'standard', '{}', 'Lower body (Booty Buster) waar het kan verzwaren met gewichten
1 squat 3 x 10
2 deadlift 3 x 10
3 zijwaartse lunge 3 x 10
4 calf raise 3 x 10
5 glute bridge 3 x 10', 1, 1),
  (15, '61ZF8H295FDAHJ3GSYAZQBTKJW', 'b3-d3-sweat-smile-and-repeat', 'Sweat Smile and Repeat', 'abs', 'Abs', 'Abs AMRRAP', NULL, 'timer op 5 minuten en zoveel mogelijk rondes', 'https://youtu.be/OMyhfg0CXUw', 'amrap', '{"duration_seconds":300}', 'Abs AMRRAP (Sweat Smile and Repeat) timer op 5 minuten en zoveel mogelijk rondes
20 russian twist
20 jack knife
20 bird dog
20 cross crunch
20 heel touches', 1, 1),
  (16, '23T1HMRHRVF6HB5CMS6CB26JP9', 'b3-d4-fit-and-fun', 'Fit and Fun', 'full_body_tabata', 'Full body Tabata', 'Full body Tabata', NULL, '20 sec werk 10 sec rust 8 herhalingen, daarna door met de volgende oefening', 'https://youtu.be/ukJkCKkmbU4', 'tabata', '{"work_seconds":20,"rest_seconds":10,"rounds_per_exercise":8}', 'Full body Tabata (Fit and Fun) 20 sec werk 10 sec rust 8 herhalingen, daarna door met de volgende oefening
1 goblet squat
2 wisselende snatch
3 boksen met gewichten (jab cross)
4 curtsy lunge', 1, 1),
  (17, '2AX91FHFE6ERJSPT74DQXPST0X', 'b3-d5-dumbell-diaries', 'Dumbell Diaries', 'upper_body', 'Upper body', 'Upperbody', NULL, 'gebruik zwaardere gewichten waar mogelijk', 'https://youtu.be/w4jnGrTUBXw', 'standard', '{}', 'Upperbody (Dumbell Diaries) gebruik zwaardere gewichten waar mogelijk
1 shoulder press 3 x 10
2 skull crusher 3 x 10
3 chest press 3 x 10
4 chest fly 3 x 10', 1, 1),
  (18, '7AD44QW3FXAPGXKF6ZS7QGYVY3', 'b3-d6-one-more-rep', 'One More Rep', 'full_body', 'Full body', 'Full body', NULL, 'met partner en/of kids (je kan verzwaren met gewichten)', 'https://youtu.be/S9-uZeG6sEs', 'circuit', '{"rounds":1}', 'Full body (One More Rep) met partner en/of kids (je kan verzwaren met gewichten)
60 squats
60 push-ups
60 achterwaartse lunges
60 bicycle crunch
60 step-ups', 1, 1),
  (19, '5K36V2N8CAS7SDB5H7TY2YCWMB', 'b4-d1-fast-and-sweaty', 'Fast and Sweaty', 'full_body_hiit', 'Full body HIIT', 'Full body HIIT', NULL, 'timer op 10 min en zoveel mogelijk rondes', 'https://youtu.be/jPDecKwCvV4', 'amrap', '{"duration_seconds":600,"round_rest_seconds":60}', 'Full body HIIT (Fast and Sweaty) timer op 10 min en zoveel mogelijk rondes
20 jumping jacks
20 plank shoulder taps
20 achterwaartse lunge
20 russian twist
20 air squat
1 minuut rust', 1, 1),
  (20, '4VKC2SP88B10VMA7W23Q3HXH8N', 'b4-d2-stronger-legs', 'Stronger Legs', 'lower_body', 'Lower body', 'Lower body', NULL, 'waar het kan verzwaren met gewichten', 'https://youtu.be/EbsC9eGDZDs', 'standard', '{}', 'Lower body (Stronger Legs) waar het kan verzwaren met gewichten
1 squat 3 x 10
2 deadlift 3 x 10
3 reverse lunge 3 x 10
4 glute bridge 3 x 10
5 calf raise 3 x 10', 1, 1),
  (21, '6K1E78VCFW5NXT3K716W02Z5TQ', 'b4-d3-core-crusher', 'Core Crusher', 'abs', 'Abs', 'Abs', NULL, 'timer op 5 minuten en zoveel mogelijk rondes', 'https://youtu.be/Q_5wBAhZP3o', 'amrap', '{"duration_seconds":300,"round_rest_seconds":60}', 'Abs (Core Crusher) timer op 5 minuten en zoveel mogelijk rondes
20 bird dog
20 cross crunch
20 heel touches
20 bicycle crunch
1 minuut rust', 1, 1),
  (22, '0DBNCZASXWTD9AGEJW6A3F9EM3', 'b4-d4-fit-and-fun', 'Fit and Fun', 'full_body_tabata', 'Full body Tabata', 'Full body Tabata', NULL, '20 sec werk 10 sec rust 8 herhalingen, daarna door met de volgende oefening', 'https://youtu.be/VUEPdtySRAs', 'tabata', '{"work_seconds":20,"rest_seconds":10,"rounds_per_exercise":8}', 'Full body Tabata (Fit and Fun) 20 sec werk 10 sec rust 8 herhalingen, daarna door met de volgende oefening
1 goblet squat
2 wisselende snatch
3 boksen (jab cross)
4 curtsy lunge
5 bicep curl', 1, 1),
  (23, '3XYY2GACQTYKJ3WDFH2SXQ4Z4J', 'b4-d5-dumbell-diaries', 'Dumbell Diaries', 'upper_body', 'Upper body', 'Upperbody', NULL, 'gebruik zwaardere gewichten waar mogelijk', 'https://youtu.be/-xjoXk6DOt0', 'standard', '{}', 'Upperbody (Dumbell Diaries) gebruik zwaardere gewichten waar mogelijk
1 shoulder press 3 x 10
2 skull crusher 3 x 10
3 chest press 3 x 10
4 chest fly 3 x 10', 1, 1),
  (24, '7R2DNJSSZJR00DMN52QPYCT7X4', 'b4-d6-weekend-warrior', 'Weekend Warrior', 'full_body', 'Full body', 'Full body', NULL, '3 rondes', 'https://youtu.be/zDthg3DX8Y4', 'circuit', '{"rounds":3,"round_rest_seconds":60}', 'Full body (Weekend Warrior) 3 rondes
10 squat
10 push-up
10 mountain climbers
10 shoulder taps
10 burpees
1 minuut rust', 1, 1),
  (25, '5FSRXW4PCHTGGTF5HQN8RRHMPK', 'b5-d1-fast-and-sweaty', 'Fast and Sweaty', 'full_body_hiit', 'Full body HIIT', 'Full body HIIT', NULL, 'timer op 10 min en zoveel mogelijk rondes', 'https://youtu.be/Ka3ZazXuZds', 'amrap', '{"duration_seconds":600,"round_rest_seconds":60}', 'Full body HIIT (Fast and Sweaty) timer op 10 min en zoveel mogelijk rondes
20 jumping jacks
20 plank shoulder taps
20 achterwaartse lunge
20 russian twist
20 air squat
1 minuut rust', 1, 1),
  (26, '31RN1T27NGWAX86DAF22HGVEW5', 'b5-d2-leg-day-love', 'Leg Day Love', 'lower_body', 'Lower body', 'Lower body', NULL, 'gebruik gewichten waar mogelijk', 'https://youtu.be/DwaleRFlaSg', 'standard', '{}', 'Lower body (Leg Day Love) gebruik gewichten waar mogelijk
1 squat 3 x 10
2 deadlift 3 x 10
3 reverse lunge 3 x 10
4 glute bridge 3 x 10
5 calf raise 3 x 10', 1, 1),
  (27, '6J1F1H1KAXBC2E086FNT79YS2Y', 'b5-d3-core-crusher', 'Core Crusher', 'abs', 'Abs', 'Abs', NULL, 'timer op 5 minuten en zoveel mogelijk rondes', 'https://youtu.be/V8fLgkvCuno', 'amrap', '{"duration_seconds":300,"round_rest_seconds":60}', 'Abs (Core Crusher) timer op 5 minuten en zoveel mogelijk rondes
20 bird dog
20 cross crunch
20 heel touches
20 bicycle crunch
1 minuut rust', 1, 1),
  (28, '2X4TV2ZV7SNBVQHGJD8YQBAF16', 'b5-d4-no-pain-no-gain', 'No Pain No Gain', 'full_body_tabata', 'Full body Tabata', 'Full body Tabata', NULL, '20 sec werk 10 sec rust, 8 herhalingen, en dan door naar de volgende oefening', 'https://youtu.be/IUH1rUnIICw', 'tabata', '{"work_seconds":20,"rest_seconds":10,"rounds_per_exercise":8}', 'Full body Tabata (No Pain No Gain) 20 sec werk 10 sec rust, 8 herhalingen, en dan door naar de volgende oefening
Front raise
Squat thruster
Plank shoulder taps
Zijwaartse lunge
Bicep curl', 1, 1),
  (29, '5XEVGRFBBJ3HAYMVSJ0BNGP91B', 'b5-d5-strong-and-steady', 'Strong and Steady', 'upper_body', 'Upper body', 'Upperbody', NULL, 'gebruik zwaardere gewichten waar mogelijk', 'https://youtu.be/1QfyfQYNL5c', 'standard', '{}', 'Upperbody (Strong and Steady) gebruik zwaardere gewichten waar mogelijk
1 shoulder press 3 x 10
2 tricep dip 3 x 10
3 row 3 x 10
4 chest press 3 x 10
5 bicep curl 3 x 10', 1, 1),
  (30, '5MD5Y75RVNMASG7QZZPZFCB2ZN', 'b5-d6-circuit-saturday', 'Circuit Saturday', 'full_body', 'Full body', 'Full body', NULL, '3 rondes', 'https://youtu.be/-unuIqF9ZhI', 'circuit', '{"rounds":3,"round_rest_seconds":60}', 'Full body (Circuit Saturday) 3 rondes
10 squat
10 push-up
10 mountain climbers
10 shoulder taps
10 burpees
1 minuut rust', 1, 1);

INSERT INTO workout_exercises (id, public_id, workout_template_id, sequence, name, sets, reps, duration_seconds, notes, source_line) VALUES
  (1, '2HF2M2XSWHQ2Y0K35HA6115X6G', 1, 1, 'jumping jacks', NULL, 20, NULL, NULL, '20 jumping jacks'),
  (2, '2K0XWD7JFT2CQYREY18ACB0QXF', 1, 2, 'plank shoulder taps', NULL, 20, NULL, NULL, '20 plank shoulder taps'),
  (3, '76CWEM0KRMMFE9ZRN0SQAZ04RM', 1, 3, 'achterwaartse lunge', NULL, 20, NULL, NULL, '20 achterwaartse lunge'),
  (4, '742ST0AP7PAGKYQS2C7DFX73VP', 1, 4, 'russian twist', NULL, 20, NULL, NULL, '20 russian twist'),
  (5, '63KP13B478VR5K4XZ04NSHCXEP', 1, 5, 'air squat', NULL, 20, NULL, NULL, '20 air squat'),
  (6, '03X1RB3XKS8CWV4WR78V14HAS3', 2, 1, 'squat', 3, 10, NULL, NULL, '1 squat 3 x 10'),
  (7, '1Z6243ESNAVR2MK5ZV9XWXMV31', 2, 2, 'deadlift', 3, 10, NULL, NULL, '2 deadlift 3 x 10'),
  (8, '690RWWPWJXKFNZSED649W1YF0P', 2, 3, 'reverse lunge', 3, 10, NULL, NULL, '3 reverse lunge 3 x 10'),
  (9, '4A0F7KGQB66FMNMFZH4ZRKZFA8', 2, 4, 'glute bridge', 3, 10, NULL, NULL, '4 glute bridge 3 x 10'),
  (10, '5MYHTT050K7VK68R6A43WDW783', 2, 5, 'calf raise', 3, 10, NULL, NULL, '5 calf raise 3 x 10'),
  (11, '2WSFH36M8KMJ051Z42ZZTS9QEX', 3, 1, 'bird dog', NULL, 20, NULL, NULL, '20 bird dog'),
  (12, '57SZJNP7T5XVN2MTNJ1SQP1HZF', 3, 2, 'cross crunch', NULL, 20, NULL, NULL, '20 cross crunch'),
  (13, '4GSWCZRD4T9MHW78ETDD31PGWN', 3, 3, 'heel touches', NULL, 20, NULL, NULL, '20 heel touches'),
  (14, '4F0XG1N27D0D3H6PD5GP42MNEN', 3, 4, 'bicycle crunch', NULL, 20, NULL, NULL, '20 bicycle crunch'),
  (15, '0VHVWC632YD6WJZ8YKB4G6G4K1', 4, 1, 'Front raise', NULL, NULL, NULL, NULL, 'Front raise'),
  (16, '3CBXEAW5C9PEM1EVWSFWNVKSGE', 4, 2, 'Squat thruster', NULL, NULL, NULL, NULL, 'Squat thruster'),
  (17, '3CVMPBNH7675XV0K497NHZBCDF', 4, 3, 'Plank shoulder taps', NULL, NULL, NULL, NULL, 'Plank shoulder taps'),
  (18, '672N8RKCCF9A5KV5AZGA6XM094', 4, 4, 'Zijwaartse lunge', NULL, NULL, NULL, NULL, 'Zijwaartse lunge'),
  (19, '0WVE4X09C90J1D2EVB48NGG1RJ', 4, 5, 'Bicep curl', NULL, NULL, NULL, NULL, 'Bicep curl'),
  (20, '64F77F963VT3909EVWHHXJ3EFR', 5, 1, 'shoulder press', 3, 10, NULL, NULL, '1 shoulder press 3 x 10'),
  (21, '53EYZPYXE77QGERW5M6ZWJWJ8Q', 5, 2, 'tricep dip', 3, 10, NULL, NULL, '2 tricep dip 3 x 10'),
  (22, '1F11GQ9D8N2ZYW8NH46ZR5VPG6', 5, 3, 'row', 3, 10, NULL, NULL, '3 row 3 x 10'),
  (23, '0RDJE6ET06M1VM53VASCM0Y04W', 5, 4, 'chest press', 3, 10, NULL, NULL, '4 chest press 3 x 10'),
  (24, '18QG070JDBY6Z04NATNYRBAYRZ', 5, 5, 'bicep curl', 3, 10, NULL, NULL, '5 bicep curl 3 x 10'),
  (25, '5SSSC6RVW3G9J817FTNZHK2J5T', 6, 1, 'squat', NULL, 10, NULL, NULL, '10 squat'),
  (26, '59MRCKEM8M9XBHY128XP303STB', 6, 2, 'push-up', NULL, 10, NULL, NULL, '10 push-up'),
  (27, '3AEHDBEDHD58XKSTRJC06EP5ZX', 6, 3, 'mountain climbers', NULL, 10, NULL, NULL, '10 mountain climbers'),
  (28, '41TQZXGJRBJW5GKA8H6HR5E2ZQ', 6, 4, 'shoulder taps', NULL, 10, NULL, NULL, '10 shoulder taps'),
  (29, '0WTE9X11C6A64J40H2HNGWN0DG', 6, 5, 'burpees', NULL, 10, NULL, NULL, '10 burpees'),
  (30, '7NP4CD7BFVJPYYPFBR3RVPPE8C', 7, 1, 'jumping jacks', NULL, 20, NULL, NULL, '20 jumping jacks'),
  (31, '3024NFA11R96RPJMTKBAC0K0N9', 7, 2, 'plank shoulder taps', NULL, 20, NULL, NULL, '20 plank shoulder taps'),
  (32, '3B63PY7XCVFSJF8DR7BNVVFETW', 7, 3, 'reverse lunge', NULL, 20, NULL, NULL, '20 reverse lunge'),
  (33, '3DGVW8QVAYKAFMEDKA60K81JV2', 7, 4, 'russian twist', NULL, 20, NULL, NULL, '20 russian twist'),
  (34, '4NHQ2FY3HJVK154G80S9CKZEAY', 7, 5, 'air squat', NULL, 20, NULL, NULL, '20 air squat'),
  (35, '4XDFCC02V639G6AF68KF6RHVYP', 8, 1, 'squat', 3, 10, NULL, NULL, '1 squat 3 x 10'),
  (36, '73V0G06RXHN5AZXAVQWT6CDSH0', 8, 2, 'deadlift', 3, 10, NULL, NULL, '2 deadlift 3 x 10'),
  (37, '1P82MYBXBDBBATTN65V3D743CW', 8, 3, 'zijwaartse lunge', 3, 10, NULL, NULL, '3 zijwaartse lunge 3 x 10'),
  (38, '11F0MHCWT90JVMKM96KNDV6MMS', 8, 4, 'calf raise', 3, 10, NULL, NULL, '4 calf raise 3 x 10'),
  (39, '5SNRC4B6E84V3BB4Z9TF3FQ0N2', 8, 5, 'glute bridge', 3, 10, NULL, NULL, '5 glute bridge 3 x 10'),
  (40, '75B1FW05ZD81RCX50SGTAG9ZS1', 9, 1, 'bird dog', NULL, 20, NULL, NULL, '20 bird dog'),
  (41, '77VPM0CPZP37S4MG1W7X7H38WJ', 9, 2, 'cross crunch', NULL, 20, NULL, NULL, '20 cross crunch'),
  (42, '2PMGT87YVE62FR982XV7VK82GH', 9, 3, 'heel touches', NULL, 20, NULL, NULL, '20 heel touches'),
  (43, '68XTWHENWBTXM5STD7TDX2HGKD', 9, 4, 'bicycle crunch', NULL, 20, NULL, NULL, '20 bicycle crunch'),
  (44, '1VK8J79SMEBXHSN989JVT8BV3V', 10, 1, 'Front raise', NULL, NULL, NULL, NULL, 'Front raise'),
  (45, '2QW6JN1XZYKENA9595FJ2DAYBF', 10, 2, 'Squat thruster', NULL, NULL, NULL, NULL, 'Squat thruster'),
  (46, '6ZJBY7BE14Q5FDQG52C2G3S3Y4', 10, 3, 'Plank shoulder taps', NULL, NULL, NULL, NULL, 'Plank shoulder taps'),
  (47, '25947QRBKQ6S0X9T2P4V0W85MA', 10, 4, 'Zijwaartse lunge', NULL, NULL, NULL, NULL, 'Zijwaartse lunge'),
  (48, '62FJYVT9RD7TH4WYQ2Y6VKX6ZT', 10, 5, 'Bicep curl', NULL, NULL, NULL, NULL, 'Bicep curl'),
  (49, '4HCCGDSVJC4QH6ETKZ7NDXFFWX', 11, 1, 'shoulder press', 3, 10, NULL, NULL, '1 shoulder press 3 x 10'),
  (50, '4EQ5Q92AMFSZJ44R52BRWMMN5T', 11, 2, 'tricep dip', 3, 10, NULL, NULL, '2 tricep dip 3 x 10'),
  (51, '51J66S7HPBWH3897PK9D1FFCA5', 11, 3, 'row', 3, 10, NULL, NULL, '3 row 3 x 10'),
  (52, '4BD92WSTMXECHV0F008NY2NC1N', 11, 4, 'chest press', 3, 10, NULL, NULL, '4 chest press 3 x 10'),
  (53, '4ZWM9YTPX281H2T5CMQRQ414BY', 11, 5, 'bicep curl', 3, 10, NULL, NULL, '5 bicep curl 3 x 10'),
  (54, '21NZARGVC9FAJY4N6XAXHE07CF', 12, 1, 'squat', NULL, 10, NULL, NULL, '10 squat'),
  (55, '0898SSMW9ABQS6KM4GEJQ8M7XD', 12, 2, 'push-up', NULL, 10, NULL, NULL, '10 push-up'),
  (56, '3GZDAAZF96H98ES6Z3987152Q5', 12, 3, 'mountain climbers', NULL, 10, NULL, NULL, '10 mountain climbers'),
  (57, '0TMS3KCRS71CNMW6Y41YXSQ88K', 12, 4, 'shoulder taps', NULL, 10, NULL, NULL, '10 shoulder taps'),
  (58, '67XH2NTSZ8HZER2RNSFY3TMBSD', 12, 5, 'burpees', NULL, 10, NULL, NULL, '10 burpees'),
  (59, '5WMB583RBBNH17A2HR28TKTKDK', 13, 1, 'jumping jacks', NULL, 10, NULL, NULL, '10 jumping jacks'),
  (60, '055ZE4YQ7MN28VCFFXBAKQYNYC', 13, 2, 'plank shoulder taps', NULL, 10, NULL, NULL, '10 plank shoulder taps'),
  (61, '6XBXC319C7MQWYFMQB90HKVFPA', 13, 3, 'om en om lunge', NULL, 10, NULL, NULL, '10 om en om lunge'),
  (62, '2EVS198K0AEZ7F0TMJ73AV4JJK', 13, 4, 'russian twist', NULL, 10, NULL, NULL, '10 russian twist'),
  (63, '7HD6ARY0QFYGDPMQWJ7MFFWF4E', 13, 5, 'air squat', NULL, 10, NULL, NULL, '10 air squat'),
  (64, '4BB86GYN69KKHJEGJB0CTF9D8N', 14, 1, 'squat', 3, 10, NULL, NULL, '1 squat 3 x 10'),
  (65, '0GQFMJPWVJCH4Q0RM1PXN9ZW25', 14, 2, 'deadlift', 3, 10, NULL, NULL, '2 deadlift 3 x 10'),
  (66, '0K1T8AYKCCCXY7JC13567GJHRG', 14, 3, 'zijwaartse lunge', 3, 10, NULL, NULL, '3 zijwaartse lunge 3 x 10'),
  (67, '63AXRGFCDF93V1C36TTEHHFDSM', 14, 4, 'calf raise', 3, 10, NULL, NULL, '4 calf raise 3 x 10'),
  (68, '5JGY00V8GWVDJQYZWMN71HHZ5D', 14, 5, 'glute bridge', 3, 10, NULL, NULL, '5 glute bridge 3 x 10'),
  (69, '2ADAVPDVVMT7RSXRGXMS8DFCXV', 15, 1, 'russian twist', NULL, 20, NULL, NULL, '20 russian twist'),
  (70, '4T4SK1FFXVVZWG9GA9BN2CWAFD', 15, 2, 'jack knife', NULL, 20, NULL, NULL, '20 jack knife'),
  (71, '6RJZAH2129QKHCSHZ7AG46W4K7', 15, 3, 'bird dog', NULL, 20, NULL, NULL, '20 bird dog'),
  (72, '71A6STWP4ZAFQ6R6DVSJE8HW14', 15, 4, 'cross crunch', NULL, 20, NULL, NULL, '20 cross crunch'),
  (73, '76R40018MGE22NR3Q9DWP8YB21', 15, 5, 'heel touches', NULL, 20, NULL, NULL, '20 heel touches'),
  (74, '75H1K8APTWT711TH83AD584S84', 16, 1, 'goblet squat', NULL, NULL, NULL, NULL, '1 goblet squat'),
  (75, '3D40JN1GS2ASF0BCCHH9PRBTAB', 16, 2, 'wisselende snatch', NULL, NULL, NULL, NULL, '2 wisselende snatch'),
  (76, '45DX1QC5XF7345AHDMAKBA8S91', 16, 3, 'boksen met gewichten (jab cross)', NULL, NULL, NULL, NULL, '3 boksen met gewichten (jab cross)'),
  (77, '33TM4RR5F8FDAA43S2K3XK5F92', 16, 4, 'curtsy lunge', NULL, NULL, NULL, NULL, '4 curtsy lunge'),
  (78, '5F0E8E4T5G1GSF9QCD4B99Z00K', 17, 1, 'shoulder press', 3, 10, NULL, NULL, '1 shoulder press 3 x 10'),
  (79, '57F8SVBWSMDHK44S7VSYVFEHH9', 17, 2, 'skull crusher', 3, 10, NULL, NULL, '2 skull crusher 3 x 10'),
  (80, '68C1909D811ZS7PF03G7G61STR', 17, 3, 'chest press', 3, 10, NULL, NULL, '3 chest press 3 x 10'),
  (81, '5VVC20PMHGNYNDB6SB72P8AT19', 17, 4, 'chest fly', 3, 10, NULL, NULL, '4 chest fly 3 x 10'),
  (82, '737QVWQWT2ZCV5DVDKXNT2189C', 18, 1, 'squats', NULL, 60, NULL, NULL, '60 squats'),
  (83, '468DMZJE2CRXD651G7G2D1BPTX', 18, 2, 'push-ups', NULL, 60, NULL, NULL, '60 push-ups'),
  (84, '3ARYD4NHVKXDZ4HF1DCVA4YQ2A', 18, 3, 'achterwaartse lunges', NULL, 60, NULL, NULL, '60 achterwaartse lunges'),
  (85, '1YT25VTNERNKXAPQ6XNY31Z123', 18, 4, 'bicycle crunch', NULL, 60, NULL, NULL, '60 bicycle crunch'),
  (86, '4APM8K6HGP2WP73FDMG3X0W1MT', 18, 5, 'step-ups', NULL, 60, NULL, NULL, '60 step-ups'),
  (87, '42VB5F4S7204F09VK84R077MYA', 19, 1, 'jumping jacks', NULL, 20, NULL, NULL, '20 jumping jacks'),
  (88, '0CDN61ZW2E9DB1FSS7G403R578', 19, 2, 'plank shoulder taps', NULL, 20, NULL, NULL, '20 plank shoulder taps'),
  (89, '05T4Q1WH7VQPJXCZHK15KKRWPH', 19, 3, 'achterwaartse lunge', NULL, 20, NULL, NULL, '20 achterwaartse lunge'),
  (90, '7FV47TP70V0Q5ERW7RC17A9PTM', 19, 4, 'russian twist', NULL, 20, NULL, NULL, '20 russian twist'),
  (91, '7K9B78JKS00W7JM4JW2MV1Z5HC', 19, 5, 'air squat', NULL, 20, NULL, NULL, '20 air squat'),
  (92, '4YNPM015XA112C9VHKM5PFZKTD', 20, 1, 'squat', 3, 10, NULL, NULL, '1 squat 3 x 10'),
  (93, '5HNWGV7MGAHWG4RXPR8B799DVA', 20, 2, 'deadlift', 3, 10, NULL, NULL, '2 deadlift 3 x 10'),
  (94, '3CH26C5MS9H5M9BQ2GVAJ0MQAT', 20, 3, 'reverse lunge', 3, 10, NULL, NULL, '3 reverse lunge 3 x 10'),
  (95, '0VZZC9P8PMGKCYBDSX0XF2DG87', 20, 4, 'glute bridge', 3, 10, NULL, NULL, '4 glute bridge 3 x 10'),
  (96, '1EV04J29Z273S6D4H1YRR142T8', 20, 5, 'calf raise', 3, 10, NULL, NULL, '5 calf raise 3 x 10'),
  (97, '0E09FHHCNMG94P3V068FM0SDGH', 21, 1, 'bird dog', NULL, 20, NULL, NULL, '20 bird dog'),
  (98, '729WX55EGXFEBXCZQ1JJCMZ84J', 21, 2, 'cross crunch', NULL, 20, NULL, NULL, '20 cross crunch'),
  (99, '20WDN5Q1QPCN4T5W8E7Y9N89B1', 21, 3, 'heel touches', NULL, 20, NULL, NULL, '20 heel touches'),
  (100, '1FR1K82GQXWZPDVDSJCY2K509S', 21, 4, 'bicycle crunch', NULL, 20, NULL, NULL, '20 bicycle crunch'),
  (101, '5N5XK460QGRF73HDZJACK440DM', 22, 1, 'goblet squat', NULL, NULL, NULL, NULL, '1 goblet squat'),
  (102, '6ZRR9D6YDQRR3V1BTM8QGXWDV9', 22, 2, 'wisselende snatch', NULL, NULL, NULL, NULL, '2 wisselende snatch'),
  (103, '3N45V7N4RCRYBGDX603947T00M', 22, 3, 'boksen (jab cross)', NULL, NULL, NULL, NULL, '3 boksen (jab cross)'),
  (104, '1ZS4NYWH5T1VQKJ7KK4YY65P1J', 22, 4, 'curtsy lunge', NULL, NULL, NULL, NULL, '4 curtsy lunge'),
  (105, '6E820MBF4SS8624HCHC4MG94PH', 22, 5, 'bicep curl', NULL, NULL, NULL, NULL, '5 bicep curl'),
  (106, '43G58NQJXMBHE97YAFDDEVTCF6', 23, 1, 'shoulder press', 3, 10, NULL, NULL, '1 shoulder press 3 x 10'),
  (107, '5PPWK5GJGV72F1BSN66E3NHCNZ', 23, 2, 'skull crusher', 3, 10, NULL, NULL, '2 skull crusher 3 x 10'),
  (108, '3N3TPMK6JD0D8HVGHTJ791J93J', 23, 3, 'chest press', 3, 10, NULL, NULL, '3 chest press 3 x 10'),
  (109, '7999T6BSRZ8YT88QG81VB33HXD', 23, 4, 'chest fly', 3, 10, NULL, NULL, '4 chest fly 3 x 10'),
  (110, '4P4TEZETW0EYW0RYV7Q201XWW3', 24, 1, 'squat', NULL, 10, NULL, NULL, '10 squat'),
  (111, '7A8ADD4ZSG5HGRWYG9PBKVR26P', 24, 2, 'push-up', NULL, 10, NULL, NULL, '10 push-up'),
  (112, '2002Q1C7RMYGK81Y5D26FG8TJM', 24, 3, 'mountain climbers', NULL, 10, NULL, NULL, '10 mountain climbers'),
  (113, '7FGT2BPD2M1Y0MGYFQ4BWA4620', 24, 4, 'shoulder taps', NULL, 10, NULL, NULL, '10 shoulder taps'),
  (114, '77NZQV4EWB265JPCT23J1B4250', 24, 5, 'burpees', NULL, 10, NULL, NULL, '10 burpees'),
  (115, '7YKYDM1A3SZ9JKSKMPJF1HMN45', 25, 1, 'jumping jacks', NULL, 20, NULL, NULL, '20 jumping jacks'),
  (116, '7X2FXQ2WS1MRND0SATQ5R1P0VX', 25, 2, 'plank shoulder taps', NULL, 20, NULL, NULL, '20 plank shoulder taps'),
  (117, '3DY1PT4CRKK3FNFD802M1WJA7P', 25, 3, 'achterwaartse lunge', NULL, 20, NULL, NULL, '20 achterwaartse lunge'),
  (118, '38ZTVHCM4J2PV867G16Z1P6PNY', 25, 4, 'russian twist', NULL, 20, NULL, NULL, '20 russian twist'),
  (119, '0E88KVCS8ZT6NECE725DCEGSC6', 25, 5, 'air squat', NULL, 20, NULL, NULL, '20 air squat'),
  (120, '1SG9RAB2NY654R4DHF2DRBNQPA', 26, 1, 'squat', 3, 10, NULL, NULL, '1 squat 3 x 10'),
  (121, '3W879YRD3CBSXWPCPBYN5XM88D', 26, 2, 'deadlift', 3, 10, NULL, NULL, '2 deadlift 3 x 10'),
  (122, '2C3C96NR3VG4DEJVBRRCR4ATXV', 26, 3, 'reverse lunge', 3, 10, NULL, NULL, '3 reverse lunge 3 x 10'),
  (123, '0D3JC4H3H34GPK1PH0GJFX86YS', 26, 4, 'glute bridge', 3, 10, NULL, NULL, '4 glute bridge 3 x 10'),
  (124, '2N9BMNZVNQQBRYMXCP7P2G5ZV4', 26, 5, 'calf raise', 3, 10, NULL, NULL, '5 calf raise 3 x 10'),
  (125, '1PXRYR4QG73XQBZQAPQ3J8NKRP', 27, 1, 'bird dog', NULL, 20, NULL, NULL, '20 bird dog'),
  (126, '0MX7TF3JF9BCE62B4RTAHD38JF', 27, 2, 'cross crunch', NULL, 20, NULL, NULL, '20 cross crunch'),
  (127, '1A6CBCC3AYDD18HAGCZ96EPV49', 27, 3, 'heel touches', NULL, 20, NULL, NULL, '20 heel touches'),
  (128, '49SKNNX1JKT1FVKMXDSDATEJ2D', 27, 4, 'bicycle crunch', NULL, 20, NULL, NULL, '20 bicycle crunch'),
  (129, '7EV6V3J5YXFTFWRE7T8MNA6S26', 28, 1, 'Front raise', NULL, NULL, NULL, NULL, 'Front raise'),
  (130, '2AHAZWYKQDKT0KW5WPQZXVP1TH', 28, 2, 'Squat thruster', NULL, NULL, NULL, NULL, 'Squat thruster'),
  (131, '49WQ4RM0JNMPPAEP8G1RJ0WSYD', 28, 3, 'Plank shoulder taps', NULL, NULL, NULL, NULL, 'Plank shoulder taps'),
  (132, '5RTX1J13B074CTSVZ2BWQC3YR3', 28, 4, 'Zijwaartse lunge', NULL, NULL, NULL, NULL, 'Zijwaartse lunge'),
  (133, '13PDT9GZC33ZC696NREVPF4XCJ', 28, 5, 'Bicep curl', NULL, NULL, NULL, NULL, 'Bicep curl'),
  (134, '6EKHABWGV8C0JAJXMTB3N0Z4XG', 29, 1, 'shoulder press', 3, 10, NULL, NULL, '1 shoulder press 3 x 10'),
  (135, '05D92X8R5B6SE3X34HM8Z82BSK', 29, 2, 'tricep dip', 3, 10, NULL, NULL, '2 tricep dip 3 x 10'),
  (136, '0C7GBSN08G84PCT0JJCXXZ16FP', 29, 3, 'row', 3, 10, NULL, NULL, '3 row 3 x 10'),
  (137, '2T0WKW4QW9Z1EX3BP4DZH5WRY4', 29, 4, 'chest press', 3, 10, NULL, NULL, '4 chest press 3 x 10'),
  (138, '2ZEE3TDXGVB242YY00C8SA7A75', 29, 5, 'bicep curl', 3, 10, NULL, NULL, '5 bicep curl 3 x 10'),
  (139, '04V5QJAVQSSPK95340K7JEFMZJ', 30, 1, 'squat', NULL, 10, NULL, NULL, '10 squat'),
  (140, '2WEW3RF277Q86TFV6MZ7CAGWPQ', 30, 2, 'push-up', NULL, 10, NULL, NULL, '10 push-up'),
  (141, '3H42RNT8BT4TNG6TBGPKPFEV7X', 30, 3, 'mountain climbers', NULL, 10, NULL, NULL, '10 mountain climbers'),
  (142, '54G3H7M0SRC5H3W9H6YYP32JXX', 30, 4, 'shoulder taps', NULL, 10, NULL, NULL, '10 shoulder taps'),
  (143, '1M0H45DR5SXRW0CREH13JRF0SW', 30, 5, 'burpees', NULL, 10, NULL, NULL, '10 burpees');

INSERT INTO block_workouts (id, public_id, training_block_id, workout_template_id, sequence, day_label) VALUES
  (1, '3CTN7JXC0EWG65F4AY6CYJA563', 1, 1, 1, 'maandag'),
  (2, '431FTAS49FB9E7Q3N3H4SN5KBA', 1, 2, 2, 'dinsdag'),
  (3, '52H2E1R1F2NVEE7X6PJTQ52C0Q', 1, 3, 3, 'woensdag'),
  (4, '3R276XCW7YY4HXPVWKF0WYYVFQ', 1, 4, 4, 'donderdag'),
  (5, '28E14C5G67YJWW2JD8EJRVHMWT', 1, 5, 5, 'vrijdag'),
  (6, '4TNY7FXX651209MX8AT48JRNVW', 1, 6, 6, 'zaterdag'),
  (7, '3TZGKVD20DP8CVV73EVR0ZMTA4', 2, 7, 1, 'maandag'),
  (8, '3XB1BPKAX0CDN159N0WHJ3FAD3', 2, 8, 2, 'dinsdag'),
  (9, '6FDXCZQ9JPA1BQ9Z075TXKJFFZ', 2, 9, 3, 'woensdag'),
  (10, '4ZEDN1VQA9TV4KPTZ3XKW8E3R6', 2, 10, 4, 'donderdag'),
  (11, '12HTET4KWBR6S7NQV1KNN732NY', 2, 11, 5, 'vrijdag'),
  (12, '273HHHSATQSABHDK8SSDXQ16N8', 2, 12, 6, 'zaterdag'),
  (13, '653M3T0AV61PMTTRQQEKY9QZEN', 3, 13, 1, 'maandag'),
  (14, '01531RR316TPSPSKZYVP5WDS23', 3, 14, 2, 'dinsdag'),
  (15, '5ST13AENKPP8HS4ZJVKWQDXEJE', 3, 15, 3, 'woensdag'),
  (16, '6V1N7MBSQYE5DWJ2ZAJD8GD1E1', 3, 16, 4, 'donderdag'),
  (17, '6982AR5JGPR5WPFMMN06TMAZK4', 3, 17, 5, 'vrijdag'),
  (18, '5555TV1DBJX381594AVZ0BQS6C', 3, 18, 6, 'zaterdag'),
  (19, '4KMG15QQSG6H33HH63RKF840F4', 4, 19, 1, 'maandag'),
  (20, '4YAYEF9V0NQPYA00CH5GC8DE53', 4, 20, 2, 'dinsdag'),
  (21, '2ERZ6M964GJA3H1M9S9P6YK344', 4, 21, 3, 'woensdag'),
  (22, '3QSH0TH5NY821XVK4MZENKC9KX', 4, 22, 4, 'donderdag'),
  (23, '3SX74TBP5RN77K645V09ZXWQFP', 4, 23, 5, 'vrijdag'),
  (24, '2P6PW88F543YYXEWJKS0SZQS1W', 4, 24, 6, 'zaterdag'),
  (25, '4NB9A6H4YHQHGGE28B106BZ5YF', 5, 25, 1, 'maandag'),
  (26, '408GT6DB6MTSATTAMQZMSB1MGC', 5, 26, 2, 'dinsdag'),
  (27, '7MX4G3JVQBJ3PGEKJJAK8QN9D0', 5, 27, 3, 'woensdag'),
  (28, '3NMWHZ2DYZM7HQNYJ2QBP8N3FV', 5, 28, 4, 'donderdag'),
  (29, '4A1ND6F5XZV7XHF57W2ARTS5DV', 5, 29, 5, 'vrijdag'),
  (30, '486PZ9J5CN1BJ0BJ151YT21YJY', 5, 30, 6, 'zaterdag');

COMMIT;

-- Set AUTO_INCREMENT values above seeded IDs.
ALTER TABLE programs AUTO_INCREMENT = 2;
ALTER TABLE training_blocks AUTO_INCREMENT = 6;
ALTER TABLE workout_templates AUTO_INCREMENT = 31;
ALTER TABLE workout_exercises AUTO_INCREMENT = 144;
ALTER TABLE block_workouts AUTO_INCREMENT = 31;
