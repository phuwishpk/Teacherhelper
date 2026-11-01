/// What น้องเช็ค is doing (DESIGN §43).
enum MascotMood {
  /// An ordinary answer.
  idle,

  /// An empty chat and the first-run slides.
  wave,

  /// Waiting for the AI.
  thinking,

  /// The teacher's plan was created.
  happy,

  /// The AI could not answer.
  sorry,
}
